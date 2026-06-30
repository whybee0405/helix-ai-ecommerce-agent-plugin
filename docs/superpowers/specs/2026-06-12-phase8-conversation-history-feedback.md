# Phase 8 — Conversation History & Merchant Feedback Design Spec

**Date:** 2026-06-12  
**Status:** Approved  
**Scope:** Persist widget chat conversations; expose read endpoints for merchants; allow customers to rate assistant responses  
**Definition of done:** Widget chat turns are stored in DB; merchants can retrieve conversation history via API; customers can submit thumbs_up/thumbs_down feedback on assistant messages.

---

## 1. Gap analysis from Phase 7

| Gap | Impact |
|-----|--------|
| Widget chat is stateless — queries and responses are never stored | Merchants cannot audit what the AI told customers; no data for pack improvement |
| No conversation context — each question is independent | Customers cannot ask follow-up questions that reference previous answers |
| No feedback mechanism | Merchant has no signal for which AI responses were helpful or harmful |

---

## 2. Data model (P8-1)

### `Conversation`

```python
class Conversation(Base):
    __tablename__ = "conversation"

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    tenant_id: Mapped[UUID] = mapped_column(
        ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False, index=True
    )
    customer_id: Mapped[Optional[UUID]] = mapped_column(
        ForeignKey("customer.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now
    )
    updated_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now, onupdate=_now
    )
```

### `ConversationMessage`

```python
class ConversationMessage(Base):
    __tablename__ = "conversation_message"

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    conversation_id: Mapped[UUID] = mapped_column(
        ForeignKey("conversation.id", ondelete="CASCADE"), nullable=False, index=True
    )
    tenant_id: Mapped[UUID] = mapped_column(
        ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False, index=True
    )
    role: Mapped[str] = mapped_column(String(16), nullable=False)      # "user" | "assistant"
    content: Mapped[str] = mapped_column(Text, nullable=False)
    source: Mapped[Optional[str]] = mapped_column(String(16), nullable=True)   # "template"|"rules"|"llm"|None
    products_referenced: Mapped[list] = mapped_column(JSONB, nullable=False, default=list)
    feedback: Mapped[Optional[str]] = mapped_column(String(16), nullable=True)  # "thumbs_up"|"thumbs_down"|None
    created_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now
    )
```

Both models go in `eshopeo/db/models.py` alongside existing models.

### Migration 0003

New file `eshopeo/db/migrations/versions/0003_conversations.py`:
- Creates `conversation` table
- Creates `conversation_message` table

---

## 3. CRUD layer (P8-1)

New file `eshopeo/db/crud/conversations.py`:

```python
async def create_conversation(session, tenant_id, customer_id=None) -> Conversation
async def append_messages(session, conversation_id, tenant_id, user_content, assistant_content, source, products_referenced) -> tuple[ConversationMessage, ConversationMessage]
async def get_conversation(session, conversation_id, tenant_id) -> Conversation | None
async def list_conversations(session, tenant_id, limit=20, offset=0) -> list[Conversation]
async def get_messages(session, conversation_id, tenant_id) -> list[ConversationMessage]
async def get_message(session, message_id, tenant_id) -> ConversationMessage | None
async def set_message_feedback(session, message_id, tenant_id, feedback) -> ConversationMessage | None
```

`append_messages` inserts two rows atomically (user turn + assistant turn) and returns both. The assistant message row is the one that receives feedback later.

---

## 4. Widget integration (P8-2)

### `ChatRequest` change

```python
class ChatRequest(BaseModel):
    query: str
    customer_id: str | None = None
    customer_profile: dict = {}
    conversation_id: str | None = None  # UUID of existing conversation to append to
```

### `ChatResponse` change

```python
class ChatResponse(BaseModel):
    response: str
    source: str
    products_referenced: list[str] = []
    conversation_id: str          # always returned
    assistant_message_id: str     # UUID of the assistant ConversationMessage
```

### `_run_chat_pipeline` change

After `handle_query` returns:

```python
# Resolve conversation
if body.conversation_id:
    try:
        conv_id = UUID(body.conversation_id)
        conversation = await get_conversation(db, conv_id, tenant.id)
    except ValueError:
        conversation = None
else:
    conversation = None

if conversation is None:
    cid_for_conv = cid if body.customer_id and 'cid' in locals() else None
    conversation = await create_conversation(db, tenant.id, cid_for_conv)

user_msg, assistant_msg = await append_messages(
    db,
    conversation_id=conversation.id,
    tenant_id=tenant.id,
    user_content=body.query,
    assistant_content=result.response,
    source=result.source,
    products_referenced=result.products_referenced,
)
```

Returns a `ConversationInfo` named tuple (or simple object) with `conversation_id` and `assistant_message_id`. Both `widget_chat` and `widget_chat_stream` include these in their response.

**Architecture note**: `_run_chat_pipeline` currently returns `RouteResult`. Extend it to also return conversation metadata. The cleanest approach: return a dataclass `PipelineResult(route: RouteResult, conversation_id: UUID, assistant_message_id: UUID)`. This avoids polluting `RouteResult` with conversation concerns.

### SSE streaming response change

`widget_chat_stream` includes `conversation_id` and `assistant_message_id` in the `done` event:

```json
{"type": "done", "source": "llm", "conversation_id": "...", "assistant_message_id": "..."}
```

---

## 5. Conversation list + detail endpoints (P8-3)

New router `eshopeo/api/routers/conversations.py` registered at `/v1/conversations`.

Auth: `get_tenant` (merchant-facing).

### `GET /v1/conversations`

Query params: `limit: int = 20` (1-100), `offset: int = 0`

Response:
```json
{
  "conversations": [
    {
      "id": "...",
      "customer_id": "..." | null,
      "message_count": 4,
      "last_message_at": "2026-06-12T08:00:00Z",
      "preview": "What moisturizer works for oily skin?"
    }
  ],
  "total": 42
}
```

The `preview` is the content of the first user message in the conversation (truncated to 100 chars).

Implementation: `list_conversations()` CRUD returns `Conversation` rows; join or subquery for message count + first message preview via SQLAlchemy.

### `GET /v1/conversations/{conversation_id}`

Response:
```json
{
  "id": "...",
  "tenant_id": "...",
  "customer_id": "..." | null,
  "created_at": "...",
  "messages": [
    {"id": "...", "role": "user", "content": "...", "source": null, "feedback": null, "created_at": "..."},
    {"id": "...", "role": "assistant", "content": "...", "source": "llm", "feedback": "thumbs_up", "created_at": "..."}
  ]
}
```

Returns 404 if conversation not found or belongs to different tenant.

---

## 6. Message feedback endpoint (P8-4)

### `POST /v1/widget/conversations/{message_id}/feedback`

Auth: `get_widget_tenant` (customer-initiated from embed JS).

Request body:
```json
{"feedback": "thumbs_up" | "thumbs_down"}
```

Response: `{"message_id": "...", "feedback": "thumbs_up"}`

Logic:
1. Look up `ConversationMessage` by `(message_id, tenant_id)` — tenant_id from JWT
2. Verify `role == "assistant"` (can only rate assistant messages)
3. If not found or wrong role → 404
4. Call `set_message_feedback(session, message_id, tenant_id, body.feedback)`
5. `await db.commit()`

The endpoint is on the widget router (`/v1/widget/conversations/{message_id}/feedback`) so it's accessible from the embed JS using the widget JWT token.

---

## 7. File map

**New files:**
- `services/core/eshopeo/db/crud/conversations.py`
- `services/core/eshopeo/db/migrations/versions/0003_conversations.py`
- `services/core/eshopeo/api/routers/conversations.py`
- `services/core/tests/test_conversation_crud.py` (5 tests)
- `services/core/tests/test_widget_conversation.py` (4 tests)
- `services/core/tests/test_conversations_endpoint.py` (4 tests)
- `services/core/tests/test_message_feedback.py` (3 tests)

**Modified files:**
- `services/core/eshopeo/db/models.py` — add `Conversation` and `ConversationMessage`
- `services/core/eshopeo/api/routers/widget.py` — `ChatRequest` + `ChatResponse` + `_run_chat_pipeline` return type
- `services/core/eshopeo/api/app.py` — register conversations router

---

## 8. Test plan

### test_conversation_crud.py (5 tests)
1. `test_create_conversation_sets_tenant_id` — create_conversation, assert tenant_id set
2. `test_append_messages_creates_two_rows` — append_messages, assert 2 ConversationMessage rows, correct roles
3. `test_get_conversation_tenant_scoped` — create 2 conversations for 2 tenants, get_conversation with wrong tenant_id returns None
4. `test_get_messages_returns_ordered` — append messages, get_messages returns user then assistant in order
5. `test_set_message_feedback_updates_field` — set_message_feedback, assert feedback field updated

### test_widget_conversation.py (4 tests)
1. `test_chat_response_includes_conversation_id` — mock handle_query + CRUD, assert response has `conversation_id`
2. `test_chat_response_includes_assistant_message_id` — assert `assistant_message_id` in response
3. `test_chat_creates_new_conversation_when_none_provided` — no conversation_id in request, assert create_conversation called
4. `test_chat_appends_to_existing_conversation` — valid conversation_id in request, assert create_conversation NOT called, append_messages called with correct conversation_id

### test_conversations_endpoint.py (4 tests)
1. `test_list_conversations_returns_200` — mock list_conversations returning 1 item, assert 200 + structure
2. `test_get_conversation_returns_messages` — mock get_conversation + get_messages, assert message list in response
3. `test_get_conversation_404_when_not_found` — mock get_conversation returning None → 404
4. `test_conversations_requires_auth` — no X-eShopeo-Tenant-Key → 401

### test_message_feedback.py (3 tests)
1. `test_feedback_accepted_thumbs_up` — mock get_message returning assistant message, set_message_feedback called, 200
2. `test_feedback_404_on_unknown_message` — mock get_message returning None → 404
3. `test_feedback_requires_widget_auth` — no Authorization header → 401

---

## 9. Security constraints

- Conversation read endpoints: `get_tenant` auth — merchants only, scoped by tenant_id
- Feedback endpoint: `get_widget_tenant` (JWT) — customer-initiated, tenant_id from token
- All CRUD queries WHERE tenant_id == tenant.id — cross-tenant isolation enforced at data layer
- `conversation_id` from ChatRequest treated as untrusted — invalid UUID silently creates new conversation
- `message_id` in feedback URL validated as UUID — invalid → 404 not 500
