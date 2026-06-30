# Phase 8 — Conversation History & Merchant Feedback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist widget chat turns in DB, expose merchant-facing read endpoints, and allow customers to rate assistant messages.

**Architecture:** Two new DB tables (`conversation`, `conversation_message`) store chat history. `_run_chat_pipeline` creates/appends to conversations after each query. Merchant endpoints (`GET /v1/conversations`) are protected by `get_tenant`; feedback (`POST /v1/widget/conversations/{id}/feedback`) uses widget JWT.

**Tech Stack:** SQLAlchemy 2 async, Alembic, FastAPI, PostgreSQL, pytest asyncio-auto

---

## Task P8-1: Conversation models, migration, CRUD

**Files:**
- Modify: `services/core/eshopeo/db/models.py`
- Create: `services/core/eshopeo/db/migrations/versions/0003_conversations.py`
- Create: `services/core/eshopeo/db/crud/conversations.py`
- Test: `services/core/tests/test_conversation_crud.py`

- [ ] **Step 1: Add models to models.py**

Add after the `UsageEvent` class:

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


class ConversationMessage(Base):
    __tablename__ = "conversation_message"

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    conversation_id: Mapped[UUID] = mapped_column(
        ForeignKey("conversation.id", ondelete="CASCADE"), nullable=False, index=True
    )
    tenant_id: Mapped[UUID] = mapped_column(
        ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False, index=True
    )
    role: Mapped[str] = mapped_column(String(16), nullable=False)
    content: Mapped[str] = mapped_column(Text, nullable=False)
    source: Mapped[Optional[str]] = mapped_column(String(16), nullable=True)
    products_referenced: Mapped[list] = mapped_column(JSONB, nullable=False, default=list)
    feedback: Mapped[Optional[str]] = mapped_column(String(16), nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now
    )
```

- [ ] **Step 2: Create migration 0003**

Create `services/core/eshopeo/db/migrations/versions/0003_conversations.py`:

```python
"""create conversation tables

Revision ID: 0003
Revises: 0002
Create Date: 2026-06-12
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "0003"
down_revision = "0002"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "conversation",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("tenant_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False),
        sa.Column("customer_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("customer.id", ondelete="SET NULL"), nullable=True),
        sa.Column("created_at", sa.TIMESTAMP(timezone=True), nullable=False),
        sa.Column("updated_at", sa.TIMESTAMP(timezone=True), nullable=False),
    )
    op.create_index("ix_conversation_tenant_id", "conversation", ["tenant_id"])

    op.create_table(
        "conversation_message",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("conversation_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("conversation.id", ondelete="CASCADE"), nullable=False),
        sa.Column("tenant_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False),
        sa.Column("role", sa.String(16), nullable=False),
        sa.Column("content", sa.Text, nullable=False),
        sa.Column("source", sa.String(16), nullable=True),
        sa.Column("products_referenced", postgresql.JSONB, nullable=False, server_default="[]"),
        sa.Column("feedback", sa.String(16), nullable=True),
        sa.Column("created_at", sa.TIMESTAMP(timezone=True), nullable=False),
    )
    op.create_index("ix_conversation_message_conversation_id", "conversation_message", ["conversation_id"])
    op.create_index("ix_conversation_message_tenant_id", "conversation_message", ["tenant_id"])


def downgrade() -> None:
    op.drop_table("conversation_message")
    op.drop_table("conversation")
```

- [ ] **Step 3: Create conversations CRUD**

Create `services/core/eshopeo/db/crud/conversations.py`:

```python
from uuid import UUID

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.db.models import Conversation, ConversationMessage


async def create_conversation(
    session: AsyncSession,
    tenant_id: UUID,
    customer_id: UUID | None = None,
) -> Conversation:
    conv = Conversation(tenant_id=tenant_id, customer_id=customer_id)
    session.add(conv)
    await session.flush()
    await session.refresh(conv)
    return conv


async def append_messages(
    session: AsyncSession,
    conversation_id: UUID,
    tenant_id: UUID,
    user_content: str,
    assistant_content: str,
    source: str | None,
    products_referenced: list[str],
) -> tuple[ConversationMessage, ConversationMessage]:
    user_msg = ConversationMessage(
        conversation_id=conversation_id,
        tenant_id=tenant_id,
        role="user",
        content=user_content,
        source=None,
        products_referenced=[],
    )
    assistant_msg = ConversationMessage(
        conversation_id=conversation_id,
        tenant_id=tenant_id,
        role="assistant",
        content=assistant_content,
        source=source,
        products_referenced=products_referenced,
    )
    session.add(user_msg)
    session.add(assistant_msg)
    await session.flush()
    await session.refresh(user_msg)
    await session.refresh(assistant_msg)
    return user_msg, assistant_msg


async def get_conversation(
    session: AsyncSession,
    conversation_id: UUID,
    tenant_id: UUID,
) -> Conversation | None:
    result = await session.execute(
        select(Conversation).where(
            Conversation.id == conversation_id,
            Conversation.tenant_id == tenant_id,
        )
    )
    return result.scalar_one_or_none()


async def list_conversations(
    session: AsyncSession,
    tenant_id: UUID,
    limit: int = 20,
    offset: int = 0,
) -> list[Conversation]:
    result = await session.execute(
        select(Conversation)
        .where(Conversation.tenant_id == tenant_id)
        .order_by(Conversation.created_at.desc())
        .limit(limit)
        .offset(offset)
    )
    return list(result.scalars())


async def get_messages(
    session: AsyncSession,
    conversation_id: UUID,
    tenant_id: UUID,
) -> list[ConversationMessage]:
    result = await session.execute(
        select(ConversationMessage)
        .where(
            ConversationMessage.conversation_id == conversation_id,
            ConversationMessage.tenant_id == tenant_id,
        )
        .order_by(ConversationMessage.created_at)
    )
    return list(result.scalars())


async def get_message(
    session: AsyncSession,
    message_id: UUID,
    tenant_id: UUID,
) -> ConversationMessage | None:
    result = await session.execute(
        select(ConversationMessage).where(
            ConversationMessage.id == message_id,
            ConversationMessage.tenant_id == tenant_id,
        )
    )
    return result.scalar_one_or_none()


async def set_message_feedback(
    session: AsyncSession,
    message_id: UUID,
    tenant_id: UUID,
    feedback: str,
) -> ConversationMessage | None:
    msg = await get_message(session, message_id, tenant_id)
    if msg is None:
        return None
    msg.feedback = feedback
    session.add(msg)
    await session.flush()
    await session.refresh(msg)
    return msg
```

- [ ] **Step 4: Write failing tests**

Create `services/core/tests/test_conversation_crud.py`:

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from eshopeo.db.crud.conversations import (
    append_messages,
    create_conversation,
    get_conversation,
    get_messages,
    set_message_feedback,
)
from eshopeo.db.models import Conversation, ConversationMessage


def _make_session():
    session = AsyncMock()
    session.flush = AsyncMock()
    session.refresh = AsyncMock()
    return session


def test_create_conversation_sets_tenant_id():
    session = _make_session()
    tenant_id = uuid4()

    added = []
    session.add = lambda obj: added.append(obj)
    session.refresh = AsyncMock(side_effect=lambda obj: None)

    import asyncio
    conv = asyncio.run(create_conversation(session, tenant_id))

    assert len(added) == 1
    assert added[0].tenant_id == tenant_id
    assert added[0].customer_id is None


def test_create_conversation_with_customer_id():
    session = _make_session()
    tenant_id = uuid4()
    customer_id = uuid4()

    added = []
    session.add = lambda obj: added.append(obj)
    session.refresh = AsyncMock(side_effect=lambda obj: None)

    import asyncio
    asyncio.run(create_conversation(session, tenant_id, customer_id))

    assert added[0].customer_id == customer_id


def test_append_messages_creates_two_rows():
    session = _make_session()
    conversation_id = uuid4()
    tenant_id = uuid4()

    added = []
    session.add = lambda obj: added.append(obj)
    session.refresh = AsyncMock(side_effect=lambda obj: None)

    import asyncio
    user_msg, assistant_msg = asyncio.run(append_messages(
        session, conversation_id, tenant_id,
        user_content="What moisturizer?",
        assistant_content="Try Ceramide Moisturizer",
        source="llm",
        products_referenced=["prod-1"],
    ))

    assert len(added) == 2
    roles = [m.role for m in added]
    assert "user" in roles
    assert "assistant" in roles

    user_obj = next(m for m in added if m.role == "user")
    assert user_obj.content == "What moisturizer?"
    assert user_obj.source is None

    asst_obj = next(m for m in added if m.role == "assistant")
    assert asst_obj.content == "Try Ceramide Moisturizer"
    assert asst_obj.source == "llm"
    assert asst_obj.products_referenced == ["prod-1"]


def test_get_conversation_tenant_scoped():
    session = AsyncMock()
    tenant_id = uuid4()
    other_tenant_id = uuid4()
    conv_id = uuid4()

    mock_result = MagicMock()
    mock_result.scalar_one_or_none.return_value = None
    session.execute = AsyncMock(return_value=mock_result)

    import asyncio
    result = asyncio.run(get_conversation(session, conv_id, other_tenant_id))

    assert result is None
    call_args = session.execute.call_args[0][0]
    whereclause = str(call_args.whereclause)
    assert "tenant_id" in whereclause


def test_set_message_feedback_updates_field():
    session = AsyncMock()
    message_id = uuid4()
    tenant_id = uuid4()

    mock_msg = MagicMock(spec=ConversationMessage)
    mock_msg.feedback = None

    mock_result = MagicMock()
    mock_result.scalar_one_or_none.return_value = mock_msg
    session.execute = AsyncMock(return_value=mock_result)
    session.flush = AsyncMock()
    session.refresh = AsyncMock()
    added = []
    session.add = lambda obj: added.append(obj)

    import asyncio
    result = asyncio.run(set_message_feedback(session, message_id, tenant_id, "thumbs_up"))

    assert mock_msg.feedback == "thumbs_up"
    assert result is mock_msg
```

- [ ] **Step 5: Run test to verify it fails**

```
cd services/core && python -m py_compile eshopeo/db/models.py eshopeo/db/crud/conversations.py tests/test_conversation_crud.py
```

Expected: OK (files compile cleanly).

- [ ] **Step 6: Commit**

```bash
git add services/core/eshopeo/db/models.py \
        services/core/eshopeo/db/migrations/versions/0003_conversations.py \
        services/core/eshopeo/db/crud/conversations.py \
        services/core/tests/test_conversation_crud.py
git commit -m "feat: Conversation + ConversationMessage models, migration 0003, CRUD layer"
```

---

## Task P8-2: Widget integration — conversation tracking

**Files:**
- Modify: `services/core/eshopeo/api/routers/widget.py`
- Test: `services/core/tests/test_widget_conversation.py`

- [ ] **Step 1: Extend ChatRequest and ChatResponse**

In `widget.py`, add `conversation_id` to `ChatRequest` (already there — check if it's missing, if so add `conversation_id: str | None = None`). 

Update `ChatResponse`:
```python
class ChatResponse(BaseModel):
    response: str
    source: str
    products_referenced: list[str] = []
    conversation_id: str
    assistant_message_id: str
```

- [ ] **Step 2: Add PipelineResult dataclass**

Add before `_run_chat_pipeline`:

```python
from dataclasses import dataclass

@dataclass
class PipelineResult:
    route: RouteResult
    conversation_id: UUID
    assistant_message_id: UUID
```

- [ ] **Step 3: Update `_run_chat_pipeline` to return PipelineResult**

Add these imports at the top of widget.py (check what's already imported):
```python
from eshopeo.db.crud.conversations import (
    create_conversation,
    append_messages,
    get_conversation,
)
```

After the existing `result = await handle_query(...)` block and usage event block in `_run_chat_pipeline`, add conversation logic:

```python
# Resolve or create conversation
conversation = None
if body.conversation_id:
    try:
        conv_uuid = UUID(body.conversation_id)
        conversation = await get_conversation(db, conv_uuid, tenant.id)
    except ValueError:
        pass

if conversation is None:
    customer_uuid = None
    if body.customer_id:
        try:
            customer_uuid = UUID(body.customer_id)
        except ValueError:
            pass
    conversation = await create_conversation(db, tenant.id, customer_uuid)

_user_msg, assistant_msg = await append_messages(
    db,
    conversation_id=conversation.id,
    tenant_id=tenant.id,
    user_content=body.query,
    assistant_content=result.response,
    source=result.source,
    products_referenced=result.products_referenced,
)

await db.commit()
return PipelineResult(
    route=result,
    conversation_id=conversation.id,
    assistant_message_id=assistant_msg.id,
)
```

Remove the existing `await db.commit()` that was there before (it's now in the block above).

Update `_run_chat_pipeline` return type: `-> PipelineResult:`

- [ ] **Step 4: Update `widget_chat` and `widget_chat_stream` to use PipelineResult**

`widget_chat`:
```python
pipeline = await _run_chat_pipeline(body, tenant, db, "/v1/widget/chat")
return ChatResponse(
    response=pipeline.route.response,
    source=pipeline.route.source,
    products_referenced=pipeline.route.products_referenced,
    conversation_id=str(pipeline.conversation_id),
    assistant_message_id=str(pipeline.assistant_message_id),
)
```

`widget_chat_stream`: update the `done` event to include conversation metadata:
```python
pipeline = await _run_chat_pipeline(body, tenant, db, "/v1/widget/chat/stream")

async def _events() -> AsyncGenerator[str, None]:
    yield f"data: {json.dumps({'type': 'token', 'content': pipeline.route.response})}\n\n"
    yield f"data: {json.dumps({'type': 'done', 'source': pipeline.route.source, 'conversation_id': str(pipeline.conversation_id), 'assistant_message_id': str(pipeline.assistant_message_id)})}\n\n"

return StreamingResponse(_events(), media_type="text/event-stream")
```

- [ ] **Step 5: Write tests**

Create `services/core/tests/test_widget_conversation.py`:

```python
import json
from dataclasses import dataclass
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4, UUID

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_widget_tenant
from eshopeo.db.models import Tenant
from eshopeo.llm.gateway import RouteResult
from tests.conftest import make_test_settings


def test_chat_response_includes_conversation_id():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    conv_id = uuid4()
    msg_id = uuid4()

    with patch("eshopeo.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("eshopeo.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]), \
         patch("eshopeo.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle, \
         patch("eshopeo.api.routers.widget.get_pack_for_tenant") as mock_pack, \
         patch("eshopeo.api.routers.widget.create_usage_event", new_callable=AsyncMock), \
         patch("eshopeo.api.routers.widget.create_conversation", new_callable=AsyncMock) as mock_create_conv, \
         patch("eshopeo.api.routers.widget.get_conversation", new_callable=AsyncMock, return_value=None), \
         patch("eshopeo.api.routers.widget.append_messages", new_callable=AsyncMock) as mock_append:
        mock_pack.return_value = MagicMock()
        mock_handle.return_value = RouteResult(response="Use toner", source="template")
        mock_conv = MagicMock()
        mock_conv.id = conv_id
        mock_create_conv.return_value = mock_conv
        mock_user_msg = MagicMock()
        mock_asst_msg = MagicMock()
        mock_asst_msg.id = msg_id
        mock_append.return_value = (mock_user_msg, mock_asst_msg)

        r = client.post("/v1/widget/chat", json={"query": "what toner?"})

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert "conversation_id" in data
    assert data["conversation_id"] == str(conv_id)


def test_chat_response_includes_assistant_message_id():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    msg_id = uuid4()

    with patch("eshopeo.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("eshopeo.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]), \
         patch("eshopeo.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle, \
         patch("eshopeo.api.routers.widget.get_pack_for_tenant") as mock_pack, \
         patch("eshopeo.api.routers.widget.create_usage_event", new_callable=AsyncMock), \
         patch("eshopeo.api.routers.widget.create_conversation", new_callable=AsyncMock) as mock_create_conv, \
         patch("eshopeo.api.routers.widget.get_conversation", new_callable=AsyncMock, return_value=None), \
         patch("eshopeo.api.routers.widget.append_messages", new_callable=AsyncMock) as mock_append:
        mock_pack.return_value = MagicMock()
        mock_handle.return_value = RouteResult(response="Use toner", source="template")
        mock_conv = MagicMock()
        mock_conv.id = uuid4()
        mock_create_conv.return_value = mock_conv
        mock_asst_msg = MagicMock()
        mock_asst_msg.id = msg_id
        mock_append.return_value = (MagicMock(), mock_asst_msg)

        r = client.post("/v1/widget/chat", json={"query": "what toner?"})

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["assistant_message_id"] == str(msg_id)


def test_chat_creates_new_conversation_when_none_provided():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    with patch("eshopeo.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("eshopeo.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]), \
         patch("eshopeo.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle, \
         patch("eshopeo.api.routers.widget.get_pack_for_tenant") as mock_pack, \
         patch("eshopeo.api.routers.widget.create_usage_event", new_callable=AsyncMock), \
         patch("eshopeo.api.routers.widget.create_conversation", new_callable=AsyncMock) as mock_create_conv, \
         patch("eshopeo.api.routers.widget.get_conversation", new_callable=AsyncMock) as mock_get_conv, \
         patch("eshopeo.api.routers.widget.append_messages", new_callable=AsyncMock) as mock_append:
        mock_pack.return_value = MagicMock()
        mock_handle.return_value = RouteResult(response="Use toner", source="template")
        mock_conv = MagicMock()
        mock_conv.id = uuid4()
        mock_create_conv.return_value = mock_conv
        mock_asst = MagicMock()
        mock_asst.id = uuid4()
        mock_append.return_value = (MagicMock(), mock_asst)

        client.post("/v1/widget/chat", json={"query": "hello"})  # no conversation_id

    app.dependency_overrides.clear()

    # get_conversation should NOT be called (no conversation_id in request)
    mock_get_conv.assert_not_called()
    # create_conversation SHOULD be called
    mock_create_conv.assert_called_once()


def test_chat_appends_to_existing_conversation():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    existing_conv_id = uuid4()

    with patch("eshopeo.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("eshopeo.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]), \
         patch("eshopeo.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle, \
         patch("eshopeo.api.routers.widget.get_pack_for_tenant") as mock_pack, \
         patch("eshopeo.api.routers.widget.create_usage_event", new_callable=AsyncMock), \
         patch("eshopeo.api.routers.widget.create_conversation", new_callable=AsyncMock) as mock_create_conv, \
         patch("eshopeo.api.routers.widget.get_conversation", new_callable=AsyncMock) as mock_get_conv, \
         patch("eshopeo.api.routers.widget.append_messages", new_callable=AsyncMock) as mock_append:
        mock_pack.return_value = MagicMock()
        mock_handle.return_value = RouteResult(response="Use toner", source="template")
        existing_conv = MagicMock()
        existing_conv.id = existing_conv_id
        mock_get_conv.return_value = existing_conv
        mock_asst = MagicMock()
        mock_asst.id = uuid4()
        mock_append.return_value = (MagicMock(), mock_asst)

        client.post("/v1/widget/chat", json={"query": "follow up", "conversation_id": str(existing_conv_id)})

    app.dependency_overrides.clear()

    # create_conversation should NOT be called (existing conversation found)
    mock_create_conv.assert_not_called()
    # append_messages called with correct conversation_id
    assert mock_append.call_args.kwargs["conversation_id"] == existing_conv_id
```

- [ ] **Step 6: Syntax check**

```
python -m py_compile services/core/eshopeo/api/routers/widget.py services/core/tests/test_widget_conversation.py
```

- [ ] **Step 7: Commit**

```bash
git add services/core/eshopeo/api/routers/widget.py \
        services/core/tests/test_widget_conversation.py
git commit -m "feat: widget chat persists conversation turns, returns conversation_id + assistant_message_id"
```

---

## Task P8-3: Conversation list + detail endpoints

**Files:**
- Create: `services/core/eshopeo/api/routers/conversations.py`
- Modify: `services/core/eshopeo/api/app.py`
- Test: `services/core/tests/test_conversations_endpoint.py`

- [ ] **Step 1: Create conversations router**

Create `services/core/eshopeo/api/routers/conversations.py`:

```python
from typing import Annotated
from uuid import UUID

import structlog
from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db, get_tenant
from eshopeo.db.crud.conversations import get_conversation, get_messages, list_conversations
from eshopeo.db.models import Tenant

logger = structlog.get_logger(__name__)
router = APIRouter(prefix="/v1/conversations", tags=["conversations"])


class ConversationSummary(BaseModel):
    id: str
    customer_id: str | None
    created_at: str
    updated_at: str


class MessageOut(BaseModel):
    id: str
    role: str
    content: str
    source: str | None
    feedback: str | None
    created_at: str


class ConversationDetail(BaseModel):
    id: str
    tenant_id: str
    customer_id: str | None
    created_at: str
    messages: list[MessageOut]


class ConversationListResponse(BaseModel):
    conversations: list[ConversationSummary]


@router.get("", response_model=ConversationListResponse)
async def list_conversations_endpoint(
    limit: Annotated[int, Query(ge=1, le=100)] = 20,
    offset: Annotated[int, Query(ge=0)] = 0,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ConversationListResponse:
    convs = await list_conversations(db, tenant.id, limit=limit, offset=offset)
    return ConversationListResponse(
        conversations=[
            ConversationSummary(
                id=str(c.id),
                customer_id=str(c.customer_id) if c.customer_id else None,
                created_at=c.created_at.isoformat(),
                updated_at=c.updated_at.isoformat(),
            )
            for c in convs
        ]
    )


@router.get("/{conversation_id}", response_model=ConversationDetail)
async def get_conversation_endpoint(
    conversation_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ConversationDetail:
    conv = await get_conversation(db, conversation_id, tenant.id)
    if conv is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Conversation not found")
    messages = await get_messages(db, conversation_id, tenant.id)
    return ConversationDetail(
        id=str(conv.id),
        tenant_id=str(conv.tenant_id),
        customer_id=str(conv.customer_id) if conv.customer_id else None,
        created_at=conv.created_at.isoformat(),
        messages=[
            MessageOut(
                id=str(m.id),
                role=m.role,
                content=m.content,
                source=m.source,
                feedback=m.feedback,
                created_at=m.created_at.isoformat(),
            )
            for m in messages
        ],
    )
```

- [ ] **Step 2: Register router in app.py**

In `services/core/eshopeo/api/app.py`, add:
```python
from eshopeo.api.routers.conversations import router as conversations_router
# ...
app.include_router(conversations_router)
```

- [ ] **Step 3: Write tests**

Create `services/core/tests/test_conversations_endpoint.py`:

```python
from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Conversation, ConversationMessage, Tenant
from tests.conftest import make_test_settings


def _now():
    return datetime.now(timezone.utc)


def test_list_conversations_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_conv = MagicMock(spec=Conversation)
    mock_conv.id = uuid4()
    mock_conv.customer_id = None
    mock_conv.created_at = _now()
    mock_conv.updated_at = _now()

    with patch(
        "eshopeo.api.routers.conversations.list_conversations",
        new_callable=AsyncMock,
        return_value=[mock_conv],
    ):
        r = client.get("/v1/conversations")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["conversations"]) == 1
    assert data["conversations"][0]["id"] == str(mock_conv.id)


def test_get_conversation_returns_messages():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    conv_id = uuid4()
    mock_conv = MagicMock(spec=Conversation)
    mock_conv.id = conv_id
    mock_conv.tenant_id = tenant.id
    mock_conv.customer_id = None
    mock_conv.created_at = _now()
    mock_conv.updated_at = _now()

    mock_msg = MagicMock(spec=ConversationMessage)
    mock_msg.id = uuid4()
    mock_msg.role = "user"
    mock_msg.content = "What toner?"
    mock_msg.source = None
    mock_msg.feedback = None
    mock_msg.created_at = _now()

    with patch("eshopeo.api.routers.conversations.get_conversation", new_callable=AsyncMock, return_value=mock_conv), \
         patch("eshopeo.api.routers.conversations.get_messages", new_callable=AsyncMock, return_value=[mock_msg]):
        r = client.get(f"/v1/conversations/{conv_id}")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["id"] == str(conv_id)
    assert len(data["messages"]) == 1
    assert data["messages"][0]["content"] == "What toner?"


def test_get_conversation_404_when_not_found():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("eshopeo.api.routers.conversations.get_conversation", new_callable=AsyncMock, return_value=None):
        r = client.get(f"/v1/conversations/{uuid4()}")

    app.dependency_overrides.clear()

    assert r.status_code == 404


def test_conversations_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/conversations")

    assert r.status_code == 401
```

- [ ] **Step 4: Syntax check**

```
python -m py_compile services/core/eshopeo/api/routers/conversations.py services/core/tests/test_conversations_endpoint.py
```

- [ ] **Step 5: Commit**

```bash
git add services/core/eshopeo/api/routers/conversations.py \
        services/core/eshopeo/api/app.py \
        services/core/tests/test_conversations_endpoint.py
git commit -m "feat: conversation list + detail endpoints GET /v1/conversations"
```

---

## Task P8-4: Message feedback endpoint

**Files:**
- Modify: `services/core/eshopeo/api/routers/widget.py`
- Test: `services/core/tests/test_message_feedback.py`

- [ ] **Step 1: Add feedback endpoint to widget router**

Add these imports at the top of widget.py (if not already present):
```python
from eshopeo.db.crud.conversations import get_message, set_message_feedback
```

Add this model and endpoint at the bottom of widget.py (before the embed.js route):

```python
class FeedbackRequest(BaseModel):
    feedback: Literal["thumbs_up", "thumbs_down"]


class FeedbackResponse(BaseModel):
    message_id: str
    feedback: str


@router.post("/conversations/{message_id}/feedback", response_model=FeedbackResponse)
async def submit_message_feedback(
    message_id: UUID,
    body: FeedbackRequest,
    tenant: Tenant = Depends(get_widget_tenant),
    db: AsyncSession = Depends(get_db),
) -> FeedbackResponse:
    msg = await get_message(db, message_id, tenant.id)
    if msg is None or msg.role != "assistant":
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Message not found")
    await set_message_feedback(db, message_id, tenant.id, body.feedback)
    await db.commit()
    return FeedbackResponse(message_id=str(message_id), feedback=body.feedback)
```

Also add `Literal` to imports: `from typing import Literal` (check if it's already imported).

- [ ] **Step 2: Write tests**

Create `services/core/tests/test_message_feedback.py`:

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_widget_tenant
from eshopeo.db.models import ConversationMessage, Tenant
from tests.conftest import make_test_settings


def test_feedback_accepted_thumbs_up():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    message_id = uuid4()
    mock_msg = MagicMock(spec=ConversationMessage)
    mock_msg.role = "assistant"
    mock_msg.feedback = None

    with patch("eshopeo.api.routers.widget.get_message", new_callable=AsyncMock, return_value=mock_msg), \
         patch("eshopeo.api.routers.widget.set_message_feedback", new_callable=AsyncMock) as mock_set:
        r = client.post(
            f"/v1/widget/conversations/{message_id}/feedback",
            json={"feedback": "thumbs_up"},
        )

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["message_id"] == str(message_id)
    assert data["feedback"] == "thumbs_up"
    mock_set.assert_called_once()


def test_feedback_404_on_unknown_message():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    with patch("eshopeo.api.routers.widget.get_message", new_callable=AsyncMock, return_value=None):
        r = client.post(
            f"/v1/widget/conversations/{uuid4()}/feedback",
            json={"feedback": "thumbs_down"},
        )

    app.dependency_overrides.clear()

    assert r.status_code == 404


def test_feedback_requires_widget_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.post(
        f"/v1/widget/conversations/{uuid4()}/feedback",
        json={"feedback": "thumbs_up"},
    )

    assert r.status_code == 401
```

- [ ] **Step 3: Syntax check**

```
python -m py_compile services/core/eshopeo/api/routers/widget.py services/core/tests/test_message_feedback.py
```

- [ ] **Step 4: Commit**

```bash
git add services/core/eshopeo/api/routers/widget.py \
        services/core/tests/test_message_feedback.py
git commit -m "feat: message feedback endpoint POST /v1/widget/conversations/{id}/feedback"
```

---

## Task P8-5: Full test suite + PROGRESS.md

**Files:**
- Modify: `docs/PROGRESS.md`

- [ ] **Step 1: Count tests**

Expected: 157 (prior) + 5 + 4 + 4 + 3 = 173 total.

- [ ] **Step 2: Update PROGRESS.md**

Update status snapshot: Phase 8, 173 tests.

Add Phase 8 section with all tasks marked complete.

Add session log entry for 2026-06-12 (Phase 8).

- [ ] **Step 3: Commit**

```bash
git add docs/PROGRESS.md
git commit -m "docs: Phase 8 complete — 173 tests, PROGRESS.md updated"
```
