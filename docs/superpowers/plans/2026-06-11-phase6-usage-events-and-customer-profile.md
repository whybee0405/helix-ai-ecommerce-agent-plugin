# Phase 6 — Usage Event Persistence & Customer Profile Intelligence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist LLM usage events to the database (making the analytics endpoint useful); personalize widget chat with stored customer profiles; add customer profile update endpoint.

**Architecture:** RouteResult extended with cost metadata → widget endpoint writes UsageEvent after LLM calls; get_customer_by_id CRUD added → widget merges stored + request profiles; new PATCH endpoint for profile updates.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy async, pytest asyncio_mode=auto

**Test suite baseline:** 133 tests passing at start of Phase 6.

---

### Task 1 (P6-1): Usage event persistence

**Files:**
- Create: `services/core/helix/db/crud/usage_events.py`
- Modify: `services/core/helix/llm/gateway.py` — extend RouteResult, accumulate usage in _log_usage
- Modify: `services/core/helix/api/routers/widget.py` — write UsageEvent after LLM calls
- Test: `services/core/tests/test_usage_event_persistence.py`

**Context:**
- `_log_usage(self, message, model_id, call_type)` in `gateway.py` — currently only logs to structlog; we will also accumulate usage here
- `RouteResult` in `gateway.py` — add `model: str = ""`, `tokens_in: int = 0`, `tokens_out: int = 0`, `cost_usd: float = 0.0`
- `route_query` in `gateway.py` — reset `self._last_usage` at start, read at end to populate `RouteResult`
- `widget_chat` currently has no `db.commit()` — add it
- `UsageEvent` fields: `tenant_id`, `model`, `tokens_in`, `tokens_out`, `cost_usd`, `endpoint`
- `asyncio_mode = "auto"` — NEVER `@pytest.mark.asyncio`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_usage_event_persistence.py
from unittest.mock import AsyncMock, MagicMock, patch, call
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_db, get_widget_tenant
from helix.db.models import Tenant
from helix.llm.gateway import RouteResult
from tests.conftest import make_test_settings


@pytest.fixture
def tenant():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc")
    t.id = uuid4()
    t.public_key = uuid4()
    return t


@pytest.fixture
def client(tenant):
    settings = make_test_settings()
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)
    mock_db.commit = AsyncMock()
    app.dependency_overrides[get_db] = lambda: mock_db
    app.dependency_overrides[get_widget_tenant] = lambda: tenant
    return TestClient(app), tenant, mock_db


def test_widget_chat_creates_usage_event_when_llm_called(client):
    c, tenant, _ = client
    llm_result = RouteResult(
        response="Use sunscreen", source="llm",
        model="claude-sonnet-4-6", tokens_in=100, tokens_out=50, cost_usd=0.001,
    )
    with (
        patch("helix.api.routers.widget.embed_query", AsyncMock(return_value=[0.1] * 1024)),
        patch("helix.api.routers.widget.vector_search_products", AsyncMock(return_value=[])),
        patch("helix.api.routers.widget.handle_query", AsyncMock(return_value=llm_result)),
        patch("helix.api.routers.widget.create_usage_event", AsyncMock()) as mock_usage,
    ):
        r = c.post(
            "/v1/widget/chat",
            json={"query": "best sunscreen?"},
            headers={"Authorization": "Bearer test"},
        )
    assert r.status_code == 200
    mock_usage.assert_called_once()
    call_kwargs = mock_usage.call_args.kwargs if mock_usage.call_args.kwargs else {}
    call_args = mock_usage.call_args.args
    # Either positional or keyword — cost_usd must be 0.001
    all_args = list(call_args) + list(call_kwargs.values())
    assert 0.001 in all_args or call_kwargs.get("cost_usd") == 0.001


def test_widget_chat_skips_usage_event_when_template_hit(client):
    c, tenant, _ = client
    template_result = RouteResult(
        response="We ship worldwide", source="template",
        model="", tokens_in=0, tokens_out=0, cost_usd=0.0,
    )
    with (
        patch("helix.api.routers.widget.embed_query", AsyncMock(return_value=[0.1] * 1024)),
        patch("helix.api.routers.widget.vector_search_products", AsyncMock(return_value=[])),
        patch("helix.api.routers.widget.handle_query", AsyncMock(return_value=template_result)),
        patch("helix.api.routers.widget.create_usage_event", AsyncMock()) as mock_usage,
    ):
        r = c.post(
            "/v1/widget/chat",
            json={"query": "do you ship?"},
            headers={"Authorization": "Bearer test"},
        )
    assert r.status_code == 200
    mock_usage.assert_not_called()


def test_widget_routine_creates_usage_event(client):
    c, tenant, _ = client
    llm_result = RouteResult(
        response="Your routine", source="llm",
        model="claude-sonnet-4-6", tokens_in=200, tokens_out=100, cost_usd=0.002,
    )
    mock_routine_result = MagicMock()
    mock_routine_result.steps = []
    mock_routine_result.conflicts = []
    mock_routine_result.cautions = []
    mock_routine_result.missing_steps = []
    mock_routine_result.llm_augmented = False
    with (
        patch("helix.api.routers.widget.embed_query", AsyncMock(return_value=[0.1] * 1024)),
        patch("helix.api.routers.widget.vector_search_products", AsyncMock(return_value=[])),
        patch("helix.api.routers.widget.build_routine", return_value=mock_routine_result),
        patch("helix.api.routers.widget.create_usage_event", AsyncMock()) as mock_usage,
    ):
        r = c.post(
            "/v1/widget/routine",
            json={"customer_profile": {"skin_type": "oily"}},
            headers={"Authorization": "Bearer test"},
        )
    assert r.status_code == 200
    mock_usage.assert_called_once()


def test_create_usage_event_crud_sets_fields():
    from helix.db.crud.usage_events import create_usage_event
    import inspect
    sig = inspect.signature(create_usage_event)
    params = list(sig.parameters.keys())
    assert "tenant_id" in params
    assert "model" in params
    assert "cost_usd" in params
    assert "endpoint" in params
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_usage_event_persistence.py -v
```
Expected: ImportError or 4 FAIL

- [ ] **Step 3: Create `helix/db/crud/usage_events.py`**

```python
from uuid import UUID

from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.models import UsageEvent


async def create_usage_event(
    session: AsyncSession,
    tenant_id: UUID,
    model: str,
    tokens_in: int,
    tokens_out: int,
    cost_usd: float,
    endpoint: str,
) -> UsageEvent:
    event = UsageEvent(
        tenant_id=tenant_id,
        model=model,
        tokens_in=tokens_in,
        tokens_out=tokens_out,
        cost_usd=cost_usd,
        endpoint=endpoint,
    )
    session.add(event)
    await session.flush()
    return event
```

- [ ] **Step 4: Extend `RouteResult` in `gateway.py`**

Add fields to `RouteResult.__init__`:
```python
class RouteResult:
    def __init__(
        self,
        response: str,
        source: str,
        products_referenced: list[str] | None = None,
        model: str = "",
        tokens_in: int = 0,
        tokens_out: int = 0,
        cost_usd: float = 0.0,
    ) -> None:
        self.response = response
        self.source = source
        self.products_referenced = products_referenced or []
        self.model = model
        self.tokens_in = tokens_in
        self.tokens_out = tokens_out
        self.cost_usd = cost_usd
```

- [ ] **Step 5: Add usage accumulation to `LLMGateway` in `gateway.py`**

In `__init__`, add:
```python
self._last_usage: dict = {"model": "", "tokens_in": 0, "tokens_out": 0, "cost_usd": 0.0}
```

Update `_log_usage` to accumulate:
```python
def _log_usage(self, message: anthropic.types.Message, model_id: str, call_type: str) -> None:
    in_tokens = message.usage.input_tokens
    out_tokens = message.usage.output_tokens
    in_cost, out_cost = _COSTS.get(model_id, (0.0, 0.0))
    cost_usd = (in_tokens * in_cost + out_tokens * out_cost) / 1_000_000
    self._last_usage["model"] = model_id
    self._last_usage["tokens_in"] += in_tokens
    self._last_usage["tokens_out"] += out_tokens
    self._last_usage["cost_usd"] = round(self._last_usage["cost_usd"] + cost_usd, 6)
    logger.info(
        "llm_call",
        model=model_id,
        call_type=call_type,
        tokens_in=in_tokens,
        tokens_out=out_tokens,
        cost_usd=round(cost_usd, 6),
        tenant_id=str(self._tenant_id),
    )
```

Update `route_query` to reset and read usage:
```python
async def route_query(self, ...) -> RouteResult:
    self._last_usage = {"model": "", "tokens_in": 0, "tokens_out": 0, "cost_usd": 0.0}
    # ... existing logic ...
    # In the LLM branch, after complete() returns:
    llm_result = await self.complete(...)
    return RouteResult(
        response=llm_result.response,
        source="llm",
        products_referenced=llm_result.product_ids_referenced,
        model=self._last_usage["model"],
        tokens_in=self._last_usage["tokens_in"],
        tokens_out=self._last_usage["tokens_out"],
        cost_usd=self._last_usage["cost_usd"],
    )
```

- [ ] **Step 6: Update `widget.py` to write UsageEvent**

Add import:
```python
from helix.db.crud.usage_events import create_usage_event
```

In `widget_chat`, after `result = await handle_query(...)`:
```python
result = await handle_query(...)
if result.cost_usd > 0:
    await create_usage_event(
        db, tenant.id, result.model,
        result.tokens_in, result.tokens_out,
        result.cost_usd, "/v1/widget/chat",
    )
await db.commit()
return ChatResponse(...)
```

Same pattern in `widget_routine` — after `result = build_routine(...)` — note: `build_routine` is sync and doesn't call LLM directly. However if there are LLM augmentation calls happening via the routine path, the cost would be 0 anyway. Add the commit:
```python
await db.commit()
return RoutineResponse(...)
```

For `widget_routine`, the routine builder doesn't call the LLM gateway directly (it calls `build_routine` which is sync). So usage event won't fire for routine calls in the current architecture — that's fine. Add `db.commit()` for correctness.

- [ ] **Step 7: Run tests**

```
cd services/core && python -m pytest tests/test_usage_event_persistence.py -v
```
Expected: 4 PASS

- [ ] **Step 8: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 137 PASS (133 + 4)

- [ ] **Step 9: Commit**

```
git add helix/db/crud/usage_events.py helix/llm/gateway.py helix/api/routers/widget.py \
        tests/test_usage_event_persistence.py
git commit -m "feat: persist LLM usage events to UsageEvent table after widget calls"
```

---

### Task 2 (P6-2): Customer profile in widget chat

**Files:**
- Modify: `services/core/helix/db/crud/customers.py` — add `get_customer_by_id()`
- Modify: `services/core/helix/api/routers/widget.py` — add `customer_id` to ChatRequest, merge stored profile
- Test: `services/core/tests/test_widget_customer_profile.py`

**Context:**
- `Customer.profile: Mapped[dict]` — JSONB dict storing skin profile
- `get_widget_tenant` dep validates JWT — tenant is known and scoped
- Profile merge: `{**stored_profile, **request_profile}` — request takes precedence
- Invalid UUID for `customer_id` → silently ignored (no crash)
- `asyncio_mode = "auto"` — no `@pytest.mark.asyncio`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_widget_customer_profile.py
from unittest.mock import AsyncMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_db, get_widget_tenant
from helix.db.models import Customer, Tenant
from helix.llm.gateway import RouteResult
from tests.conftest import make_test_settings


@pytest.fixture
def tenant():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc")
    t.id = uuid4()
    return t


@pytest.fixture
def customer(tenant):
    c = Customer(
        tenant_id=tenant.id, platform_id="cust-1",
        email_hash="abc", profile={"skin_type": "oily", "age_range": "25-35"},
    )
    c.id = uuid4()
    return c


@pytest.fixture
def client(tenant):
    settings = make_test_settings()
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)
    mock_db.commit = AsyncMock()
    app.dependency_overrides[get_db] = lambda: mock_db
    app.dependency_overrides[get_widget_tenant] = lambda: tenant
    return TestClient(app), tenant


CHAT_RESULT = RouteResult(response="Use retinol", source="template")


def test_chat_uses_stored_profile_when_customer_id_provided(client, customer):
    c, tenant = client
    with (
        patch("helix.api.routers.widget.embed_query", AsyncMock(return_value=[0.1] * 1024)),
        patch("helix.api.routers.widget.vector_search_products", AsyncMock(return_value=[])),
        patch("helix.api.routers.widget.handle_query", AsyncMock(return_value=CHAT_RESULT)) as mock_handle,
        patch("helix.api.routers.widget.get_customer_by_id", AsyncMock(return_value=customer)),
        patch("helix.api.routers.widget.create_usage_event", AsyncMock()),
    ):
        r = c.post(
            "/v1/widget/chat",
            json={"query": "routine?", "customer_id": str(customer.id)},
            headers={"Authorization": "Bearer test"},
        )
    assert r.status_code == 200
    call_kwargs = mock_handle.call_args.kwargs
    profile_used = call_kwargs.get("customer_profile", {})
    assert profile_used.get("skin_type") == "oily"


def test_request_profile_overrides_stored_profile(client, customer):
    c, tenant = client
    with (
        patch("helix.api.routers.widget.embed_query", AsyncMock(return_value=[0.1] * 1024)),
        patch("helix.api.routers.widget.vector_search_products", AsyncMock(return_value=[])),
        patch("helix.api.routers.widget.handle_query", AsyncMock(return_value=CHAT_RESULT)) as mock_handle,
        patch("helix.api.routers.widget.get_customer_by_id", AsyncMock(return_value=customer)),
        patch("helix.api.routers.widget.create_usage_event", AsyncMock()),
    ):
        r = c.post(
            "/v1/widget/chat",
            json={"query": "help", "customer_id": str(customer.id),
                  "customer_profile": {"skin_type": "dry"}},
            headers={"Authorization": "Bearer test"},
        )
    assert r.status_code == 200
    call_kwargs = mock_handle.call_args.kwargs
    profile_used = call_kwargs.get("customer_profile", {})
    assert profile_used.get("skin_type") == "dry"
    assert profile_used.get("age_range") == "25-35"


def test_chat_works_without_customer_id(client):
    c, tenant = client
    with (
        patch("helix.api.routers.widget.embed_query", AsyncMock(return_value=[0.1] * 1024)),
        patch("helix.api.routers.widget.vector_search_products", AsyncMock(return_value=[])),
        patch("helix.api.routers.widget.handle_query", AsyncMock(return_value=CHAT_RESULT)) as mock_handle,
        patch("helix.api.routers.widget.get_customer_by_id", AsyncMock()) as mock_lookup,
        patch("helix.api.routers.widget.create_usage_event", AsyncMock()),
    ):
        r = c.post(
            "/v1/widget/chat",
            json={"query": "help", "customer_profile": {"skin_type": "normal"}},
            headers={"Authorization": "Bearer test"},
        )
    assert r.status_code == 200
    mock_lookup.assert_not_called()
    call_kwargs = mock_handle.call_args.kwargs
    assert call_kwargs.get("customer_profile", {}).get("skin_type") == "normal"


def test_chat_ignores_invalid_customer_id(client):
    c, tenant = client
    with (
        patch("helix.api.routers.widget.embed_query", AsyncMock(return_value=[0.1] * 1024)),
        patch("helix.api.routers.widget.vector_search_products", AsyncMock(return_value=[])),
        patch("helix.api.routers.widget.handle_query", AsyncMock(return_value=CHAT_RESULT)),
        patch("helix.api.routers.widget.create_usage_event", AsyncMock()),
    ):
        r = c.post(
            "/v1/widget/chat",
            json={"query": "help", "customer_id": "not-a-uuid"},
            headers={"Authorization": "Bearer test"},
        )
    assert r.status_code == 200
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_widget_customer_profile.py -v
```
Expected: ImportError or 4 FAIL

- [ ] **Step 3: Add `get_customer_by_id` to `helix/db/crud/customers.py`**

```python
from sqlalchemy import select


async def get_customer_by_id(
    session: AsyncSession,
    customer_id: UUID,
    tenant_id: UUID,
) -> Customer | None:
    result = await session.execute(
        select(Customer).where(
            Customer.id == customer_id,
            Customer.tenant_id == tenant_id,
        )
    )
    return result.scalar_one_or_none()
```

Also add `select` to imports if not present.

- [ ] **Step 4: Update `widget.py` — `ChatRequest` and `widget_chat`**

Add `customer_id` to `ChatRequest`:
```python
class ChatRequest(BaseModel):
    query: str
    customer_profile: dict = {}
    customer_id: str | None = None
```

Add import to widget.py:
```python
from helix.db.crud.customers import get_customer_by_id
```

In `widget_chat`, before the `handle_query` call:
```python
merged_profile = body.customer_profile
if body.customer_id:
    try:
        cid = UUID(body.customer_id)
        customer = await get_customer_by_id(db, cid, tenant.id)
        if customer:
            merged_profile = {**customer.profile, **body.customer_profile}
    except ValueError:
        pass
```

Then pass `merged_profile` to `handle_query` instead of `body.customer_profile`:
```python
result = await handle_query(
    query=body.query,
    customer_profile=merged_profile,
    ...
)
```

Add `UUID` to imports if not present: `from uuid import UUID`

- [ ] **Step 5: Run tests**

```
cd services/core && python -m pytest tests/test_widget_customer_profile.py -v
```
Expected: 4 PASS

- [ ] **Step 6: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 141 PASS (137 + 4)

- [ ] **Step 7: Commit**

```
git add helix/db/crud/customers.py helix/api/routers/widget.py \
        tests/test_widget_customer_profile.py
git commit -m "feat: merge stored customer profile in widget chat when customer_id provided"
```

---

### Task 3 (P6-3): Customer profile update endpoint

**Files:**
- Modify: `services/core/helix/db/crud/customers.py` — add `get_customer_by_platform_id()` and `update_customer_profile()`
- Modify: `services/core/helix/api/routers/sync.py` — add `PATCH /v1/sync/customers/{platform_id}/profile`
- Test: `services/core/tests/test_customer_profile_update.py`

**Context:**
- `orders.py` has `get_customer_id_by_platform_id` which returns UUID — we need the full Customer object, so add a new function in `customers.py`
- Profile merge: `{**customer.profile, **body.profile}` — request keys override stored keys
- Auth: `get_tenant` dep (X-Helix-Tenant-Key)
- Return: `{"customer_id": str(customer.id), "platform_id": customer.platform_id}`
- `asyncio_mode = "auto"` — no `@pytest.mark.asyncio`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_customer_profile_update.py
from unittest.mock import AsyncMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_db, get_tenant
from helix.db.models import Customer, Tenant
from tests.conftest import make_test_settings


@pytest.fixture
def tenant():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc")
    t.id = uuid4()
    t.public_key = uuid4()
    return t


@pytest.fixture
def customer(tenant):
    c = Customer(
        tenant_id=tenant.id, platform_id="plat-cust-1",
        email_hash="abc", profile={"skin_type": "oily"},
    )
    c.id = uuid4()
    return c


@pytest.fixture
def client(tenant):
    settings = make_test_settings()
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)
    mock_db.commit = AsyncMock()
    app.dependency_overrides[get_db] = lambda: mock_db
    app.dependency_overrides[get_tenant] = lambda: tenant
    return TestClient(app), tenant


def test_patch_profile_merges_and_returns(client, customer):
    c, tenant = client
    updated = Customer(
        tenant_id=tenant.id, platform_id="plat-cust-1",
        email_hash="abc",
        profile={"skin_type": "dry", "concerns": ["acne"]},
    )
    updated.id = customer.id
    with (
        patch("helix.api.routers.sync.get_customer_by_platform_id",
              AsyncMock(return_value=customer)),
        patch("helix.api.routers.sync.update_customer_profile",
              AsyncMock(return_value=updated)),
    ):
        r = c.patch(
            "/v1/sync/customers/plat-cust-1/profile",
            json={"profile": {"skin_type": "dry", "concerns": ["acne"]}},
            headers={"X-Helix-Tenant-Key": str(tenant.public_key)},
        )
    assert r.status_code == 200
    data = r.json()
    assert data["customer_id"] == str(customer.id)
    assert data["platform_id"] == "plat-cust-1"


def test_patch_profile_404_unknown_customer(client):
    c, tenant = client
    with patch("helix.api.routers.sync.get_customer_by_platform_id",
               AsyncMock(return_value=None)):
        r = c.patch(
            "/v1/sync/customers/unknown-plat/profile",
            json={"profile": {"skin_type": "dry"}},
            headers={"X-Helix-Tenant-Key": str(tenant.public_key)},
        )
    assert r.status_code == 404


def test_patch_profile_requires_auth(client):
    c, _ = client
    r = c.patch(
        "/v1/sync/customers/plat-cust-1/profile",
        json={"profile": {"skin_type": "dry"}},
    )
    assert r.status_code == 401
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_customer_profile_update.py -v
```
Expected: 3 FAIL (route doesn't exist)

- [ ] **Step 3: Add CRUD functions to `helix/db/crud/customers.py`**

Add `select` to SQLAlchemy imports if not present.

Add `get_customer_by_platform_id`:
```python
async def get_customer_by_platform_id(
    session: AsyncSession,
    tenant_id: UUID,
    platform_id: str,
) -> Customer | None:
    result = await session.execute(
        select(Customer).where(
            Customer.tenant_id == tenant_id,
            Customer.platform_id == platform_id,
        )
    )
    return result.scalar_one_or_none()
```

Add `update_customer_profile`:
```python
async def update_customer_profile(
    session: AsyncSession,
    customer: Customer,
    new_profile: dict,
) -> Customer:
    customer.profile = new_profile
    session.add(customer)
    await session.flush()
    await session.refresh(customer)
    return customer
```

- [ ] **Step 4: Add PATCH endpoint to `helix/api/routers/sync.py`**

Add imports:
```python
from helix.db.crud.customers import get_customer_by_platform_id, update_customer_profile
```

Add Pydantic model:
```python
class CustomerProfilePatch(BaseModel):
    profile: dict
```

Add endpoint:
```python
@router.patch("/customers/{platform_id}/profile")
async def patch_customer_profile(
    platform_id: str,
    body: CustomerProfilePatch,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> dict:
    customer = await get_customer_by_platform_id(db, tenant.id, platform_id)
    if customer is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Customer not found")
    new_profile = {**customer.profile, **body.profile}
    updated = await update_customer_profile(db, customer, new_profile)
    await db.commit()
    return {"customer_id": str(updated.id), "platform_id": updated.platform_id}
```

Check that `sync.py` already imports: `HTTPException`, `status`, `Depends`, `BaseModel`, `AsyncSession`, `get_db`, `get_tenant`, `Tenant`. Add any missing ones.

- [ ] **Step 5: Run tests**

```
cd services/core && python -m pytest tests/test_customer_profile_update.py -v
```
Expected: 3 PASS

- [ ] **Step 6: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 144 PASS (141 + 3)

- [ ] **Step 7: Commit**

```
git add helix/db/crud/customers.py helix/api/routers/sync.py \
        tests/test_customer_profile_update.py
git commit -m "feat: customer profile update endpoint PATCH /v1/sync/customers/{platform_id}/profile"
```

---

### Task 4 (P6-4): Full test suite + PROGRESS.md update

**Files:**
- Update: `docs/PROGRESS.md`

- [ ] **Step 1: Run full test suite**

```
cd services/core && python -m pytest -v --tb=short
```
All tests must pass. Fix any failures before updating PROGRESS.md.

- [ ] **Step 2: Update `docs/PROGRESS.md`**

Update status snapshot to Phase 6 complete.

Add Phase 6 section:
```markdown
## Phase 6: Usage Event Persistence & Customer Profile Intelligence ✅

All 4 tasks complete.

### Tasks
- [x] Task 1: Usage event persistence — RouteResult cost fields + create_usage_event + widget commit (4 tests)
- [x] Task 2: Customer profile in widget — get_customer_by_id + merge stored+request profile (4 tests)
- [x] Task 3: Customer profile update — PATCH /v1/sync/customers/{platform_id}/profile (3 tests)
- [x] Task 4: Full suite (<N> tests) + PROGRESS.md update
```

Add session log entry at top of Session log:
```
### 2026-06-11 (Phase 6) — Claude Sonnet 4.6
Built Phase 6 usage event persistence and customer intelligence: extended RouteResult with cost metadata (model, tokens_in, tokens_out, cost_usd) accumulated via _log_usage; create_usage_event CRUD writes UsageEvent after LLM widget calls (making analytics endpoint return real data); widget chat merges stored Customer.profile with request profile when customer_id provided; PATCH /v1/sync/customers/{platform_id}/profile for profile updates (merge semantics). <N> tests total (<M> new Phase 6 + 133 prior). Next: Phase 7.
```

- [ ] **Step 3: Commit**

```
git add D:\Dev Projects\ai-ecommerce-master-plugin-beauty\docs\PROGRESS.md
git commit -m "docs: Phase 6 complete — <N> tests pass"
```
