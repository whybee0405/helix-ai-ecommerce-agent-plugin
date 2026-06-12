# Phase 9 — Conversation Context & Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Inject stored conversation history into LLM prompts for multi-turn chat; add merchant-facing analytics endpoints for conversation volume and top queries.

**Architecture:** Restructure `_run_chat_pipeline` to resolve the conversation before calling `handle_query`; fetch prior messages and pass as `conversation_history` through `handle_query` → `route_query` → `complete`. Analytics endpoints query `conversation_message` rows via new CRUD functions.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy 2.x async, Anthropic SDK, structlog, pytest (asyncio_mode=auto)

---

## Context for all tasks

- `asyncio_mode = "auto"` — NEVER add `@pytest.mark.asyncio`
- Mock namespace rule: patch at the namespace where the name is USED, not where it is defined
- Test pattern: `app.dependency_overrides[dep] = lambda: mock`; always call `app.dependency_overrides.clear()` after
- Existing CRUD in `helix/db/crud/conversations.py`: `create_conversation`, `append_messages`, `get_conversation`, `list_conversations`, `get_messages`, `get_message`, `set_message_feedback`
- `_run_chat_pipeline` is in `services/core/helix/api/routers/widget.py`
- `handle_query` is in `services/core/helix/domain/consultant.py`
- `route_query` and `complete` are in `services/core/helix/llm/gateway.py` (`LLMGateway` class)

---

## Task P9-1: Multi-turn conversation context injection

**Files:**
- Modify: `services/core/helix/llm/gateway.py`
- Modify: `services/core/helix/domain/consultant.py`
- Modify: `services/core/helix/api/routers/widget.py`
- Create: `services/core/tests/test_conversation_context.py`

### Step 1: Modify `gateway.py` — add `message_history` to `complete`

In `LLMGateway.complete`, add `message_history: list[dict] = []` as a keyword-only parameter. Change the `messages` list passed to `client.messages.create` from:
```python
messages=[{"role": "user", "content": user_with_schema}],
```
to:
```python
messages=[*message_history, {"role": "user", "content": user_with_schema}],
```

Also update the repair attempt: replace the hardcoded `messages=[...]` list with:
```python
messages=[
    *message_history,
    {"role": "user", "content": user_with_schema},
    {"role": "assistant", "content": raw},
    {"role": "user", "content": "Your response was not valid JSON. Return only the JSON object, nothing else."},
],
```

### Step 2: Modify `gateway.py` — add `conversation_history` to `route_query`

Add `conversation_history: list[dict] = []` parameter to `route_query`. Pass it only to the final `complete()` call (LLM tier), NOT to template/rules layers. Change:
```python
llm_result = await self.complete(
    tier=ModelTier.GENERATE,
    system=system_prompt,
    user=grounded_user,
    response_schema=ConsultantResponse,
    max_tokens=1024,
)
```
to:
```python
llm_result = await self.complete(
    tier=ModelTier.GENERATE,
    system=system_prompt,
    user=grounded_user,
    response_schema=ConsultantResponse,
    max_tokens=1024,
    message_history=conversation_history,
)
```

### Step 3: Modify `consultant.py` — add `conversation_history` to `handle_query`

Add `conversation_history: list[dict] = []` parameter to `handle_query`. Pass through to `gateway.route_query`:
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
        query=query,
        system_prompt=system_prompt,
        context_products=context_products,
        customer_profile=customer_profile,
        pack_rules=pack.compatibility_rules,
        pack_templates=pack.copy.get("en", {}).get("widget", {}),
        cache=cache,
        conversation_history=conversation_history,
    )
```

### Step 4: Modify `widget.py` — restructure `_run_chat_pipeline`

Add `get_messages` to the import from `helix.db.crud.conversations`.

Restructure `_run_chat_pipeline` so the conversation is resolved BEFORE `handle_query` is called:

Current order (abbreviated):
```
embed → search → merge profile → handle_query → usage event → resolve conv → append messages
```

New order:
```
embed → search → merge profile → resolve conv → fetch history → handle_query(history) → usage event → append messages
```

Extract `customer_uuid` during profile merge so it's available for `create_conversation`:

```python
# In the profile merge block:
merged_profile = body.customer_profile
customer_uuid = None
if body.customer_id:
    try:
        cid = UUID(body.customer_id)
        customer_uuid = cid
        customer = await get_customer_by_id(db, cid, tenant.id)
        if customer:
            merged_profile = {**(customer.profile or {}), **body.customer_profile}
    except ValueError:
        logger.warning("widget_chat_invalid_customer_id", customer_id=body.customer_id, endpoint=endpoint)

# Resolve conversation (moved BEFORE handle_query):
conversation = None
if body.conversation_id:
    try:
        conv_uuid = UUID(body.conversation_id)
        conversation = await get_conversation(db, conv_uuid, tenant.id)
    except ValueError:
        pass

if conversation is None:
    conversation = await create_conversation(db, tenant.id, customer_uuid)

# Fetch history (last 10 messages):
prior_messages = await get_messages(db, conversation.id, tenant.id)
conversation_history = [
    {"role": msg.role, "content": msg.content}
    for msg in prior_messages[-10:]
]

# Call handle_query with history:
result = await handle_query(
    query=body.query,
    customer_profile=merged_profile,
    context_products=context_products,
    tenant_id=tenant.id,
    pack=pack,
    settings=settings,
    db_session=db,
    conversation_history=conversation_history,
)

# Usage event (unchanged):
if result.cost_usd > 0:
    await create_usage_event(...)

# append_messages uses conversation.id (already resolved):
_user_msg, assistant_msg = await append_messages(
    db,
    conversation_id=conversation.id,
    ...
)
await db.commit()
```

Remove the old conversation-resolution block that came after `handle_query`.

### Step 5: Write `test_conversation_context.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

import pytest

from helix.llm.gateway import LLMGateway, ModelTier, ConsultantResponse
from helix.config import Settings


def _make_mock_message(role: str, content: str):
    msg = MagicMock()
    msg.role = role
    msg.content = content
    return msg


def test_context_injected_when_conversation_id_provided():
    from fastapi.testclient import TestClient
    from helix.api.app import create_app
    from helix.api.deps import get_widget_tenant, get_db
    from helix.db.models import Tenant
    from tests.conftest import make_test_settings

    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    mock_db = MagicMock()

    conv_id = uuid4()
    mock_conv = MagicMock()
    mock_conv.id = conv_id

    prior_msg_user = _make_mock_message("user", "Hello")
    prior_msg_asst = _make_mock_message("assistant", "Hi there")

    async def fake_db():
        yield mock_db

    app.dependency_overrides[get_widget_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = fake_db

    with (
        patch("helix.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024),
        patch("helix.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]),
        patch("helix.api.routers.widget.get_customer_by_id", new_callable=AsyncMock, return_value=None),
        patch("helix.api.routers.widget.get_conversation", new_callable=AsyncMock, return_value=mock_conv),
        patch("helix.api.routers.widget.get_messages", new_callable=AsyncMock, return_value=[prior_msg_user, prior_msg_asst]),
        patch("helix.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle,
        patch("helix.api.routers.widget.create_usage_event", new_callable=AsyncMock),
        patch("helix.api.routers.widget.append_messages", new_callable=AsyncMock) as mock_append,
    ):
        mock_result = MagicMock()
        mock_result.response = "reply"
        mock_result.source = "llm"
        mock_result.products_referenced = []
        mock_result.cost_usd = 0.0
        mock_handle.return_value = mock_result

        mock_user_msg = MagicMock()
        mock_asst_msg = MagicMock()
        mock_asst_msg.id = uuid4()
        mock_append.return_value = (mock_user_msg, mock_asst_msg)
        mock_db.commit = AsyncMock()

        r = client.post(
            "/v1/widget/chat",
            json={"query": "follow-up", "conversation_id": str(conv_id)},
        )

    app.dependency_overrides.clear()

    assert r.status_code == 200
    call_kwargs = mock_handle.call_args.kwargs
    assert call_kwargs["conversation_history"] == [
        {"role": "user", "content": "Hello"},
        {"role": "assistant", "content": "Hi there"},
    ]


def test_context_empty_when_no_conversation_id():
    from fastapi.testclient import TestClient
    from helix.api.app import create_app
    from helix.api.deps import get_widget_tenant, get_db
    from helix.db.models import Tenant
    from tests.conftest import make_test_settings

    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    mock_db = MagicMock()

    new_conv = MagicMock()
    new_conv.id = uuid4()

    async def fake_db():
        yield mock_db

    app.dependency_overrides[get_widget_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = fake_db

    with (
        patch("helix.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024),
        patch("helix.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]),
        patch("helix.api.routers.widget.get_customer_by_id", new_callable=AsyncMock, return_value=None),
        patch("helix.api.routers.widget.create_conversation", new_callable=AsyncMock, return_value=new_conv),
        patch("helix.api.routers.widget.get_messages", new_callable=AsyncMock, return_value=[]),
        patch("helix.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle,
        patch("helix.api.routers.widget.create_usage_event", new_callable=AsyncMock),
        patch("helix.api.routers.widget.append_messages", new_callable=AsyncMock) as mock_append,
    ):
        mock_result = MagicMock()
        mock_result.response = "hello"
        mock_result.source = "template"
        mock_result.products_referenced = []
        mock_result.cost_usd = 0.0
        mock_handle.return_value = mock_result

        mock_user_msg = MagicMock()
        mock_asst_msg = MagicMock()
        mock_asst_msg.id = uuid4()
        mock_append.return_value = (mock_user_msg, mock_asst_msg)
        mock_db.commit = AsyncMock()

        r = client.post("/v1/widget/chat", json={"query": "hi"})

    app.dependency_overrides.clear()

    assert r.status_code == 200
    call_kwargs = mock_handle.call_args.kwargs
    assert call_kwargs["conversation_history"] == []


def test_context_truncated_to_last_10_messages():
    from fastapi.testclient import TestClient
    from helix.api.app import create_app
    from helix.api.deps import get_widget_tenant, get_db
    from helix.db.models import Tenant
    from tests.conftest import make_test_settings

    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    mock_db = MagicMock()
    conv_id = uuid4()
    mock_conv = MagicMock()
    mock_conv.id = conv_id

    many_messages = [_make_mock_message("user" if i % 2 == 0 else "assistant", f"msg {i}") for i in range(14)]

    async def fake_db():
        yield mock_db

    app.dependency_overrides[get_widget_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = fake_db

    with (
        patch("helix.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024),
        patch("helix.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]),
        patch("helix.api.routers.widget.get_customer_by_id", new_callable=AsyncMock, return_value=None),
        patch("helix.api.routers.widget.get_conversation", new_callable=AsyncMock, return_value=mock_conv),
        patch("helix.api.routers.widget.get_messages", new_callable=AsyncMock, return_value=many_messages),
        patch("helix.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle,
        patch("helix.api.routers.widget.create_usage_event", new_callable=AsyncMock),
        patch("helix.api.routers.widget.append_messages", new_callable=AsyncMock) as mock_append,
    ):
        mock_result = MagicMock()
        mock_result.response = "reply"
        mock_result.source = "llm"
        mock_result.products_referenced = []
        mock_result.cost_usd = 0.0
        mock_handle.return_value = mock_result

        mock_user_msg = MagicMock()
        mock_asst_msg = MagicMock()
        mock_asst_msg.id = uuid4()
        mock_append.return_value = (mock_user_msg, mock_asst_msg)
        mock_db.commit = AsyncMock()

        r = client.post(
            "/v1/widget/chat",
            json={"query": "follow-up", "conversation_id": str(conv_id)},
        )

    app.dependency_overrides.clear()

    assert r.status_code == 200
    history = mock_handle.call_args.kwargs["conversation_history"]
    assert len(history) == 10
    assert history[0]["content"] == "msg 4"
    assert history[-1]["content"] == "msg 13"


async def test_gateway_complete_prepends_history():
    import anthropic
    from helix.config import Settings

    settings = Settings(
        anthropic_api_key="sk-ant-test",
        database_url="postgresql+asyncpg://x:x@localhost/x",
        redis_url="redis://localhost",
        session_secret="secret",
        fernet_key="Y2JjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=",
        provision_key="prov",
        voyage_api_key="voy-test",
    )
    gw = LLMGateway(settings=settings, tenant_id=uuid4())

    captured = {}

    async def fake_create(**kwargs):
        captured["messages"] = kwargs["messages"]
        fake_msg = MagicMock(spec=anthropic.types.Message)
        fake_msg.content = [MagicMock(text='{"response": "ok", "product_ids_referenced": []}')]
        fake_msg.usage = MagicMock(input_tokens=10, output_tokens=5)
        return fake_msg

    history = [{"role": "user", "content": "prior question"}]

    with patch("helix.llm.gateway.anthropic.AsyncAnthropic") as mock_cls:
        mock_client = AsyncMock()
        mock_cls.return_value = mock_client
        mock_client.messages.create = fake_create

        await gw.complete(
            tier=ModelTier.GENERATE,
            system="You are helpful",
            user="current question",
            response_schema=ConsultantResponse,
            message_history=history,
        )

    assert len(captured["messages"]) == 2
    assert captured["messages"][0] == {"role": "user", "content": "prior question"}
    assert "current question" in captured["messages"][1]["content"]
```

### Step 6: Run syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core" && python -m py_compile helix/llm/gateway.py helix/domain/consultant.py helix/api/routers/widget.py tests/test_conversation_context.py
```

### Step 7: Commit

```bash
git add services/core/helix/llm/gateway.py \
        services/core/helix/domain/consultant.py \
        services/core/helix/api/routers/widget.py \
        services/core/tests/test_conversation_context.py
git commit -m "feat: multi-turn conversation context injection into LLM layer"
```

---

## Task P9-2: Conversation analytics endpoint

**Files:**
- Modify: `services/core/helix/db/crud/conversations.py`
- Modify: `services/core/helix/api/routers/analytics.py`
- Create: `services/core/tests/test_conversation_analytics.py`

### Step 1: Add `get_conversation_analytics` to `conversations.py`

```python
from datetime import date
from sqlalchemy import func, case

async def get_conversation_analytics(
    session: AsyncSession,
    tenant_id: UUID,
    start: date,
    end: date,
) -> dict:
    from datetime import datetime, timezone, timedelta
    start_dt = datetime(start.year, start.month, start.day, tzinfo=timezone.utc)
    end_dt = datetime(end.year, end.month, end.day, tzinfo=timezone.utc) + timedelta(days=1)

    conv_result = await session.execute(
        select(func.count(Conversation.id)).where(
            Conversation.tenant_id == tenant_id,
            Conversation.created_at >= start_dt,
            Conversation.created_at < end_dt,
        )
    )
    total_conversations = conv_result.scalar_one() or 0

    msg_result = await session.execute(
        select(
            func.count(ConversationMessage.id),
            func.count(ConversationMessage.feedback),
            func.sum(
                case(
                    (ConversationMessage.feedback == "thumbs_up", 1),
                    else_=0,
                )
            ),
        ).where(
            ConversationMessage.tenant_id == tenant_id,
            ConversationMessage.created_at >= start_dt,
            ConversationMessage.created_at < end_dt,
        )
    )
    total_messages, feedback_count, feedback_positive_count = msg_result.one()
    total_messages = total_messages or 0
    feedback_count = feedback_count or 0
    feedback_positive_count = int(feedback_positive_count or 0)

    avg_messages = (
        round(total_messages / total_conversations, 1)
        if total_conversations > 0
        else 0.0
    )
    feedback_positive_rate = (
        round(feedback_positive_count / feedback_count, 2)
        if feedback_count > 0
        else None
    )

    return {
        "total_conversations": total_conversations,
        "total_messages": total_messages,
        "avg_messages_per_conversation": avg_messages,
        "feedback_count": feedback_count,
        "feedback_positive_rate": feedback_positive_rate,
    }
```

### Step 2: Add endpoint to `analytics.py`

Add import: `from helix.db.crud.conversations import get_conversation_analytics`

Add models and endpoint:

```python
class ConversationAnalytics(BaseModel):
    period: dict
    total_conversations: int
    total_messages: int
    avg_messages_per_conversation: float
    feedback_count: int
    feedback_positive_rate: float | None


@router.get("/conversations", response_model=ConversationAnalytics)
async def get_conversation_analytics_endpoint(
    start_date: date | None = Query(default=None),
    end_date: date | None = Query(default=None),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ConversationAnalytics:
    today = date.today()
    start = start_date or (today - timedelta(days=30))
    end = end_date or today

    stats = await get_conversation_analytics(db, tenant.id, start, end)
    return ConversationAnalytics(
        period={"start": str(start), "end": str(end)},
        **stats,
    )
```

### Step 3: Write `test_conversation_analytics.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def test_conversation_analytics_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_stats = {
        "total_conversations": 5,
        "total_messages": 20,
        "avg_messages_per_conversation": 4.0,
        "feedback_count": 8,
        "feedback_positive_rate": 0.75,
    }

    with patch(
        "helix.api.routers.analytics.get_conversation_analytics",
        new_callable=AsyncMock,
        return_value=mock_stats,
    ):
        r = client.get("/v1/analytics/conversations")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["total_conversations"] == 5
    assert data["feedback_positive_rate"] == 0.75
    assert "period" in data


def test_conversation_analytics_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/conversations")

    assert r.status_code == 401


def test_conversation_analytics_zero_when_no_data():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_stats = {
        "total_conversations": 0,
        "total_messages": 0,
        "avg_messages_per_conversation": 0.0,
        "feedback_count": 0,
        "feedback_positive_rate": None,
    }

    with patch(
        "helix.api.routers.analytics.get_conversation_analytics",
        new_callable=AsyncMock,
        return_value=mock_stats,
    ):
        r = client.get("/v1/analytics/conversations")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["feedback_positive_rate"] is None
```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core" && python -m py_compile helix/db/crud/conversations.py helix/api/routers/analytics.py tests/test_conversation_analytics.py
```

### Step 5: Commit

```bash
git add services/core/helix/db/crud/conversations.py \
        services/core/helix/api/routers/analytics.py \
        services/core/tests/test_conversation_analytics.py
git commit -m "feat: conversation analytics endpoint GET /v1/analytics/conversations"
```

---

## Task P9-3: Top queries endpoint

**Files:**
- Modify: `services/core/helix/db/crud/conversations.py`
- Modify: `services/core/helix/api/routers/analytics.py`
- Create: `services/core/tests/test_top_queries.py`

### Step 1: Add `get_top_queries` to `conversations.py`

```python
async def get_top_queries(
    session: AsyncSession,
    tenant_id: UUID,
    limit: int = 10,
    start: date | None = None,
    end: date | None = None,
) -> list[dict]:
    from datetime import datetime, timezone, timedelta

    stmt = (
        select(
            ConversationMessage.content,
            func.count(ConversationMessage.id).label("cnt"),
        )
        .where(
            ConversationMessage.tenant_id == tenant_id,
            ConversationMessage.role == "user",
        )
    )

    if start:
        start_dt = datetime(start.year, start.month, start.day, tzinfo=timezone.utc)
        stmt = stmt.where(ConversationMessage.created_at >= start_dt)
    if end:
        end_dt = datetime(end.year, end.month, end.day, tzinfo=timezone.utc) + timedelta(days=1)
        stmt = stmt.where(ConversationMessage.created_at < end_dt)

    stmt = stmt.group_by(ConversationMessage.content).order_by(func.count(ConversationMessage.id).desc()).limit(limit)

    result = await session.execute(stmt)
    return [{"query": row.content, "count": row.cnt} for row in result.all()]
```

### Step 2: Add endpoint to `analytics.py`

Add import: `from helix.db.crud.conversations import get_conversation_analytics, get_top_queries`

Add models and endpoint:

```python
class TopQueryItem(BaseModel):
    query: str
    count: int


class TopQueriesResponse(BaseModel):
    queries: list[TopQueryItem]


@router.get("/top-queries", response_model=TopQueriesResponse)
async def get_top_queries_endpoint(
    limit: int = Query(default=10, ge=1, le=50),
    start_date: date | None = Query(default=None),
    end_date: date | None = Query(default=None),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> TopQueriesResponse:
    queries = await get_top_queries(db, tenant.id, limit=limit, start=start_date, end=end_date)
    return TopQueriesResponse(queries=[TopQueryItem(**q) for q in queries])
```

### Step 3: Write `test_top_queries.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def test_top_queries_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_queries = [
        {"query": "best moisturizer for oily skin", "count": 12},
        {"query": "niacinamide with vitamin c", "count": 8},
    ]

    with patch(
        "helix.api.routers.analytics.get_top_queries",
        new_callable=AsyncMock,
        return_value=mock_queries,
    ):
        r = client.get("/v1/analytics/top-queries")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["queries"]) == 2
    assert data["queries"][0]["query"] == "best moisturizer for oily skin"
    assert data["queries"][0]["count"] == 12


def test_top_queries_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/top-queries")

    assert r.status_code == 401


def test_top_queries_empty_list():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch(
        "helix.api.routers.analytics.get_top_queries",
        new_callable=AsyncMock,
        return_value=[],
    ):
        r = client.get("/v1/analytics/top-queries")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["queries"] == []
```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core" && python -m py_compile helix/db/crud/conversations.py helix/api/routers/analytics.py tests/test_top_queries.py
```

### Step 5: Commit

```bash
git add services/core/helix/db/crud/conversations.py \
        services/core/helix/api/routers/analytics.py \
        services/core/tests/test_top_queries.py
git commit -m "feat: top queries analytics endpoint GET /v1/analytics/top-queries"
```

---

## Task P9-4: Full suite + PROGRESS.md

**Files:**
- Modify: `docs/PROGRESS.md`

Update `docs/PROGRESS.md`:
- Status: Phase 9 complete, 184/184 tests pass (174 prior + 4 + 3 + 3 = 184)
- Add Phase 9 section and session log entry
- Commit: `git add docs/PROGRESS.md && git commit -m "docs: Phase 9 complete — 184 tests"`
