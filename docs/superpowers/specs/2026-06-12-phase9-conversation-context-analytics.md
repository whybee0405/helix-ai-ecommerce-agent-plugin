# Phase 9 — Conversation Context & Analytics Design Spec

**Date:** 2026-06-12  
**Status:** Approved  
**Scope:** Inject stored conversation history into the LLM prompt for follow-up questions; add merchant-facing analytics endpoints for conversation volume and top customer queries.  
**Definition of done:** Multi-turn chat works (follow-up questions reference prior answers); merchants can retrieve conversation volume stats and top query terms via the API.

---

## 1. Gap analysis from Phase 8

| Gap | Impact |
|-----|--------|
| Conversation history stored but never read back | Follow-up questions ("what about that one?") lose context — AI restarts cold every turn |
| No analytics on conversation volume or feedback rates | Merchants can't measure engagement or answer quality |
| No visibility into what customers are asking most | Merchants can't improve their pack copy or FAQ coverage |

---

## 2. Multi-turn context injection (P9-1)

### Architecture

History is injected **only at Layer 4 (LLM)**. Template and rules layers remain stateless (they are keyword/rule lookups, not generative). If the template or rules layer handles the query, history is ignored.

Limit: **last 10 messages** (5 turns) prepended before the current user turn. This bounds token usage and keeps context recent.

### `_run_chat_pipeline` restructure

Current order: embed → search → merge profile → **handle_query** → usage event → resolve conversation → append messages

New order: embed → search → merge profile → **resolve conversation** → **fetch history** → **handle_query (with history)** → usage event → append messages

This restructure is correct because:
- Resolving the conversation before `handle_query` means `conversation.id` is available for `append_messages` without a second pass
- An empty new conversation produces an empty history → no change to LLM behavior
- `get_messages` on a brand-new conversation returns `[]` → correct

### Change: `widget.py` — `_run_chat_pipeline`

```python
# After merge_profile, before handle_query:

conversation = None
if body.conversation_id:
    try:
        conv_uuid = UUID(body.conversation_id)
        conversation = await get_conversation(db, conv_uuid, tenant.id)
    except ValueError:
        pass

if conversation is None:
    conversation = await create_conversation(db, tenant.id, customer_uuid)

prior_messages = await get_messages(db, conversation.id, tenant.id)
conversation_history = [
    {"role": msg.role, "content": msg.content}
    for msg in prior_messages[-10:]
]

result = await handle_query(
    ...
    conversation_history=conversation_history,
)

# append_messages uses conversation.id (already resolved above)
_user_msg, assistant_msg = await append_messages(
    db,
    conversation_id=conversation.id,
    ...
)
```

Import `get_messages` from `eshopeo.db.crud.conversations`.

### Change: `consultant.py` — `handle_query`

```python
async def handle_query(
    query: str,
    customer_profile: dict,
    context_products: list[dict],
    tenant_id: UUID,
    pack: LoadedPack,
    settings: Settings,
    db_session,
    conversation_history: list[dict] = [],
) -> RouteResult:
    ...
    result = await gateway.route_query(
        ...
        conversation_history=conversation_history,
    )
```

### Change: `gateway.py` — `route_query` + `complete`

`route_query` gains `conversation_history: list[dict] = []` param. Only passed to the final `complete()` call (LLM layer), not to template/rules.

```python
async def route_query(
    self,
    ...
    conversation_history: list[dict] = [],
) -> RouteResult:
    ...
    # template + rules unchanged (no history)

    llm_result = await self.complete(
        tier=ModelTier.GENERATE,
        system=system_prompt,
        user=grounded_user,
        response_schema=ConsultantResponse,
        max_tokens=1024,
        message_history=conversation_history,
    )
```

`complete` gains `message_history: list[dict] = []` param:

```python
async def complete(
    self,
    tier: ModelTier,
    system: str,
    user: str,
    response_schema: Type[T],
    *,
    max_tokens: int = 1024,
    message_history: list[dict] = [],
) -> T:
    ...
    messages = [
        *message_history,
        {"role": "user", "content": user_with_schema},
    ]
    message = await client.messages.create(
        model=model_id,
        max_tokens=max_tokens,
        system=[...],
        messages=messages,
    )
    ...
    # repair attempt also uses messages:
    repair_messages = [
        *messages,
        {"role": "assistant", "content": raw},
        {"role": "user", "content": "Your response was not valid JSON. ..."},
    ]
```

### Tests — `test_conversation_context.py` (4 tests)

1. `test_context_injected_when_conversation_id_provided` — mock `get_messages` returning 2 prior messages; mock `handle_query`; assert `handle_query` called with `conversation_history` of length 2
2. `test_context_empty_when_no_conversation_id` — no `conversation_id` in request; assert `handle_query` called with `conversation_history=[]`
3. `test_context_truncated_to_last_10_messages` — mock `get_messages` returning 14 messages; assert `conversation_history` has 10 entries (last 10)
4. `test_gateway_complete_prepends_history` — call `LLMGateway.complete()` with `message_history=[{"role":"user","content":"prior"}]`; mock `client.messages.create`; assert messages list has 2 entries (history + current)

---

## 3. Conversation analytics (P9-2)

### New CRUD: `get_conversation_analytics` in `conversations.py`

```python
async def get_conversation_analytics(
    session: AsyncSession,
    tenant_id: UUID,
    start: date,
    end: date,
) -> dict:
    # total conversations
    # total messages (all roles)
    # feedback_count (non-null feedback)
    # feedback_positive_count (feedback == "thumbs_up")
    # returns dict with computed metrics
```

Use SQLAlchemy `func.count`, `func.avg`, date range filter on `Conversation.created_at`.

### New endpoint in `analytics.py`

```
GET /v1/analytics/conversations
```

Query params: `start_date` (default 30 days ago), `end_date` (default today)  
Auth: `get_tenant`

Response:
```json
{
  "period": {"start": "2026-05-12", "end": "2026-06-12"},
  "total_conversations": 42,
  "total_messages": 168,
  "avg_messages_per_conversation": 4.0,
  "feedback_count": 30,
  "feedback_positive_rate": 0.73
}
```

`feedback_positive_rate` = `feedback_positive_count / feedback_count` if `feedback_count > 0` else `null`.

### Tests — `test_conversation_analytics.py` (3 tests)

1. `test_conversation_analytics_returns_200` — mock `get_conversation_analytics`, assert 200 + metrics keys present
2. `test_conversation_analytics_requires_auth` — no `X-eShopeo-Tenant-Key` → 401
3. `test_conversation_analytics_zero_when_no_data` — mock returns zero counts, assert `feedback_positive_rate` is null/None

---

## 4. Top queries (P9-3)

### New CRUD: `get_top_queries` in `conversations.py`

```python
async def get_top_queries(
    session: AsyncSession,
    tenant_id: UUID,
    limit: int = 10,
    start: date | None = None,
    end: date | None = None,
) -> list[dict]:
    # SELECT content, COUNT(*) as cnt
    # FROM conversation_message
    # WHERE tenant_id = :tid AND role = 'user'
    #   AND created_at >= :start AND created_at <= :end + 1 day
    # GROUP BY content
    # ORDER BY cnt DESC
    # LIMIT :limit
    # Returns [{"query": str, "count": int}, ...]
```

### New endpoint in `analytics.py`

```
GET /v1/analytics/top-queries
```

Query params: `limit: int = 10` (1-50), optional `start_date`, `end_date`  
Auth: `get_tenant`

Response:
```json
{
  "queries": [
    {"query": "What moisturizer works for oily skin?", "count": 12},
    {"query": "Is niacinamide safe with vitamin C?", "count": 8}
  ]
}
```

### Tests — `test_top_queries.py` (3 tests)

1. `test_top_queries_returns_200` — mock `get_top_queries` returning 2 items, assert 200 + queries list
2. `test_top_queries_requires_auth` — no `X-eShopeo-Tenant-Key` → 401
3. `test_top_queries_empty_list` — mock returns empty list, assert `queries: []`

---

## 5. File map

**Modified files:**
- `services/core/eshopeo/llm/gateway.py` — `complete` + `route_query` gain `message_history`/`conversation_history` params
- `services/core/eshopeo/domain/consultant.py` — `handle_query` gains `conversation_history` param
- `services/core/eshopeo/api/routers/widget.py` — `_run_chat_pipeline` restructured (resolve conversation before handle_query, fetch history, pass to handle_query)
- `services/core/eshopeo/db/crud/conversations.py` — add `get_conversation_analytics`, `get_top_queries`
- `services/core/eshopeo/api/routers/analytics.py` — add two new endpoints

**New files:**
- `services/core/tests/test_conversation_context.py` (4 tests)
- `services/core/tests/test_conversation_analytics.py` (3 tests)
- `services/core/tests/test_top_queries.py` (3 tests)

---

## 6. Security constraints

- All CRUD queries scoped by `tenant_id` — no cross-tenant data leakage
- `conversation_history` entries come from DB (tenant-scoped), not from user input directly
- History content is never logged at info level (may contain PII)
- `get_top_queries` groups on raw content — no PII sanitization required by this endpoint, but merchants are responsible for their own data
