# Phase 7 — Streaming Widget Chat, Search Suggestions & Quota Visibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add SSE streaming to widget chat; add product title autocomplete to search; add quota usage status to analytics.

**Architecture:** Streaming endpoint wraps existing `handle_query()` pipeline and yields result as SSE events (v1 full-response approach — no gateway refactor needed); suggestions use ILIKE prefix query; quota reads the same Redis key the quota middleware writes.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy async, pytest asyncio_mode=auto

**Test suite baseline:** 147 tests passing at start of Phase 7.

---

### Task 1 (P7-1): Streaming widget chat

**Files:**
- Modify: `services/core/eshopeo/api/routers/widget.py` — add `POST /v1/widget/chat/stream`
- Test: `services/core/tests/test_widget_chat_stream.py`

**Context:**
- `widget_chat` in `eshopeo/api/routers/widget.py` — existing endpoint to mirror
- `handle_query` returns `RouteResult(response, source, model, tokens_in, tokens_out, cost_usd)`
- `create_usage_event` already imported; `get_customer_by_id` already imported; `UUID` already imported
- `json` must be imported if not present
- `StreamingResponse` from `fastapi.responses`
- SSE format: `f"data: {json.dumps(event_dict)}\n\n"` (two newlines end each event)
- `asyncio_mode = "auto"` — NEVER `@pytest.mark.asyncio`
- `TestClient` buffers the full streaming response — parse events from `r.text`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_widget_chat_stream.py
import json
from unittest.mock import AsyncMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db, get_widget_tenant
from eshopeo.db.models import Tenant
from eshopeo.llm.gateway import RouteResult
from tests.conftest import make_test_settings


@pytest.fixture
def tenant():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc")
    t.id = uuid4()
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
    return TestClient(app), tenant


def _parse_events(text: str) -> list[dict]:
    lines = [l for l in text.split("\n") if l.startswith("data: ")]
    return [json.loads(l[6:]) for l in lines]


TEMPLATE_RESULT = RouteResult(response="We ship worldwide", source="template")


def test_chat_stream_returns_event_stream_content_type(client):
    c, tenant = client
    with (
        patch("eshopeo.api.routers.widget.embed_query", AsyncMock(return_value=[0.1] * 1024)),
        patch("eshopeo.api.routers.widget.vector_search_products", AsyncMock(return_value=[])),
        patch("eshopeo.api.routers.widget.handle_query", AsyncMock(return_value=TEMPLATE_RESULT)),
        patch("eshopeo.api.routers.widget.create_usage_event", AsyncMock()),
    ):
        r = c.post(
            "/v1/widget/chat/stream",
            json={"query": "shipping?"},
            headers={"Authorization": "Bearer test"},
        )
    assert r.status_code == 200
    assert "text/event-stream" in r.headers["content-type"]


def test_chat_stream_contains_token_and_done_events(client):
    c, tenant = client
    with (
        patch("eshopeo.api.routers.widget.embed_query", AsyncMock(return_value=[0.1] * 1024)),
        patch("eshopeo.api.routers.widget.vector_search_products", AsyncMock(return_value=[])),
        patch("eshopeo.api.routers.widget.handle_query", AsyncMock(return_value=TEMPLATE_RESULT)),
        patch("eshopeo.api.routers.widget.create_usage_event", AsyncMock()),
    ):
        r = c.post(
            "/v1/widget/chat/stream",
            json={"query": "shipping?"},
            headers={"Authorization": "Bearer test"},
        )
    events = _parse_events(r.text)
    types = {e["type"] for e in events}
    assert "token" in types
    assert "done" in types
    token_events = [e for e in events if e["type"] == "token"]
    assert token_events[0]["content"] == "We ship worldwide"


def test_chat_stream_passes_source_in_done_event(client):
    c, tenant = client
    llm_result = RouteResult(response="Use retinol", source="llm",
                              model="claude-sonnet-4-6", tokens_in=50, tokens_out=20, cost_usd=0.001)
    with (
        patch("eshopeo.api.routers.widget.embed_query", AsyncMock(return_value=[0.1] * 1024)),
        patch("eshopeo.api.routers.widget.vector_search_products", AsyncMock(return_value=[])),
        patch("eshopeo.api.routers.widget.handle_query", AsyncMock(return_value=llm_result)),
        patch("eshopeo.api.routers.widget.create_usage_event", AsyncMock()),
    ):
        r = c.post(
            "/v1/widget/chat/stream",
            json={"query": "retinol?"},
            headers={"Authorization": "Bearer test"},
        )
    events = _parse_events(r.text)
    done_events = [e for e in events if e["type"] == "done"]
    assert done_events[0]["source"] == "llm"


def test_chat_stream_requires_auth(client):
    c, _ = client
    # Remove the widget tenant dep override for this test
    from eshopeo.api.deps import get_widget_tenant as real_dep
    settings = make_test_settings()
    app = create_app(settings)
    r = TestClient(app).post(
        "/v1/widget/chat/stream",
        json={"query": "help"},
    )
    assert r.status_code == 401
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_widget_chat_stream.py -v
```
Expected: 4 FAIL (route doesn't exist)

- [ ] **Step 3: Add `POST /v1/widget/chat/stream` to `widget.py`**

Add imports (check for duplicates):
```python
import json
from fastapi.responses import StreamingResponse
```

Add endpoint after `widget_chat`:
```python
@router.post("/chat/stream")
async def widget_chat_stream(
    body: ChatRequest,
    tenant: Tenant = Depends(get_widget_tenant),
    db: AsyncSession = Depends(get_db),
) -> StreamingResponse:
    settings = get_settings()
    pack = get_pack_for_tenant(tenant)

    merged_profile = body.customer_profile
    if body.customer_id:
        try:
            cid = UUID(body.customer_id)
            customer = await get_customer_by_id(db, cid, tenant.id)
            if customer:
                merged_profile = {**(customer.profile or {}), **body.customer_profile}
        except ValueError:
            logger.warning("widget_chat_stream_invalid_customer_id", customer_id=body.customer_id)

    query_vector = await embed_query(body.query, settings)
    product_rows = await vector_search_products(db, tenant.id, query_vector, limit=5)
    context_products = [
        {
            "title": p.title,
            "price_minor": p.price_minor,
            "currency": p.currency,
            "categories": p.categories or [],
            "domain_attributes": p.domain_attributes or {},
        }
        for p, _ in product_rows
    ]

    result = await handle_query(
        query=body.query,
        customer_profile=merged_profile,
        context_products=context_products,
        tenant_id=tenant.id,
        pack=pack,
        settings=settings,
        db_session=db,
    )

    if result.cost_usd > 0:
        await create_usage_event(
            db, tenant.id, result.model,
            result.tokens_in, result.tokens_out,
            result.cost_usd, "/v1/widget/chat/stream",
        )
    await db.commit()

    async def _events():
        yield f"data: {json.dumps({'type': 'token', 'content': result.response})}\n\n"
        yield f"data: {json.dumps({'type': 'done', 'source': result.source})}\n\n"

    return StreamingResponse(_events(), media_type="text/event-stream")
```

- [ ] **Step 4: Run tests**

```
cd services/core && python -m pytest tests/test_widget_chat_stream.py -v
```
Expected: 4 PASS

- [ ] **Step 5: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 151 PASS (147 + 4)

- [ ] **Step 6: Commit**

```
git add eshopeo/api/routers/widget.py tests/test_widget_chat_stream.py
git commit -m "feat: SSE streaming chat endpoint POST /v1/widget/chat/stream"
```

---

### Task 2 (P7-2): Search product title suggestions

**Files:**
- Modify: `services/core/eshopeo/db/crud/products.py` — add `suggest_product_titles()`
- Modify: `services/core/eshopeo/api/routers/search.py` — add `GET /v1/search/suggest`
- Test: `services/core/tests/test_search_suggest.py`

**Context:**
- `Product.title.ilike(f"{prefix}%")` — SQLAlchemy ILIKE (case-insensitive) prefix match
- `select(Product.title)` — only fetch the title column, not the full model
- Auth: `get_tenant` (X-eShopeo-Tenant-Key)
- Response: `{"suggestions": [...], "prefix": "ton"}`
- `asyncio_mode = "auto"` — NEVER `@pytest.mark.asyncio`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_search_suggest.py
from unittest.mock import AsyncMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db, get_tenant
from eshopeo.db.models import Tenant
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
    app.dependency_overrides[get_db] = lambda: mock_db
    app.dependency_overrides[get_tenant] = lambda: tenant
    return TestClient(app), tenant


def test_suggest_returns_matching_titles(client):
    c, tenant = client
    with patch("eshopeo.api.routers.search.suggest_product_titles",
               AsyncMock(return_value=["Toner A", "Toner Serum"])):
        r = c.get(
            "/v1/search/suggest?q=ton",
            headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
        )
    assert r.status_code == 200
    data = r.json()
    assert data["suggestions"] == ["Toner A", "Toner Serum"]
    assert data["prefix"] == "ton"


def test_suggest_empty_results_ok(client):
    c, tenant = client
    with patch("eshopeo.api.routers.search.suggest_product_titles",
               AsyncMock(return_value=[])):
        r = c.get(
            "/v1/search/suggest?q=xyz",
            headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
        )
    assert r.status_code == 200
    assert r.json()["suggestions"] == []


def test_suggest_requires_auth(client):
    c, _ = client
    r = c.get("/v1/search/suggest?q=ton")
    assert r.status_code == 401
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_search_suggest.py -v
```
Expected: 3 FAIL

- [ ] **Step 3: Add `suggest_product_titles` to `eshopeo/db/crud/products.py`**

```python
async def suggest_product_titles(
    session: AsyncSession,
    tenant_id: UUID,
    prefix: str,
    limit: int = 5,
) -> list[str]:
    result = await session.execute(
        select(Product.title)
        .where(
            Product.tenant_id == tenant_id,
            Product.title.ilike(f"{prefix}%"),
        )
        .order_by(Product.title)
        .limit(limit)
    )
    return [row.title for row in result]
```

(Verify `select` is already imported at top of products.py — it should be.)

- [ ] **Step 4: Add `GET /v1/search/suggest` to `eshopeo/api/routers/search.py`**

Add import:
```python
from eshopeo.db.crud.products import suggest_product_titles, vector_search_products
```
(Check for duplicates — `vector_search_products` may already be imported separately.)

Add Pydantic model:
```python
class SuggestResponse(BaseModel):
    suggestions: list[str]
    prefix: str
```

Add endpoint:
```python
@router.get("/suggest", response_model=SuggestResponse)
async def suggest_products(
    q: Annotated[str, Query(min_length=1)],
    limit: Annotated[int, Query(ge=1, le=20)] = 5,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> SuggestResponse:
    suggestions = await suggest_product_titles(db, tenant.id, q, limit)
    return SuggestResponse(suggestions=suggestions, prefix=q)
```

- [ ] **Step 5: Run tests**

```
cd services/core && python -m pytest tests/test_search_suggest.py -v
```
Expected: 3 PASS

- [ ] **Step 6: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 154 PASS (151 + 3)

- [ ] **Step 7: Commit**

```
git add eshopeo/db/crud/products.py eshopeo/api/routers/search.py tests/test_search_suggest.py
git commit -m "feat: product title suggestions GET /v1/search/suggest"
```

---

### Task 3 (P7-3): Quota status endpoint

**Files:**
- Modify: `services/core/eshopeo/api/routers/analytics.py` — add `GET /v1/analytics/quota`
- Test: `services/core/tests/test_quota_status.py`

**Context:**
- Quota middleware key: `f"quota:{tenant.id}:{YYYY-MM}"` (same format)
- Import: `import redis.asyncio as aioredis` and `from datetime import datetime, timezone`
- Config: `settings.default_monthly_query_limit` and `settings.redis_url`
- Fails open: if Redis unavailable, `used=0`
- Auth: `get_tenant` (existing dep in analytics router)
- `asyncio_mode = "auto"` — no `@pytest.mark.asyncio`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_quota_status.py
from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db, get_tenant
from eshopeo.db.models import Tenant
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
    app.dependency_overrides[get_db] = lambda: mock_db
    app.dependency_overrides[get_tenant] = lambda: tenant
    return TestClient(app), tenant, settings


def test_quota_status_returns_used_count(client):
    c, tenant, settings = client
    mock_redis = AsyncMock()
    mock_redis.get = AsyncMock(return_value="3421")
    mock_redis.aclose = AsyncMock()
    with patch("eshopeo.api.routers.analytics.aioredis.from_url", return_value=mock_redis):
        r = c.get(
            "/v1/analytics/quota",
            headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
        )
    assert r.status_code == 200
    data = r.json()
    assert data["used"] == 3421
    assert data["limit"] == settings.default_monthly_query_limit
    assert data["remaining"] == settings.default_monthly_query_limit - 3421
    assert "period" in data


def test_quota_status_zero_when_key_missing(client):
    c, tenant, _ = client
    mock_redis = AsyncMock()
    mock_redis.get = AsyncMock(return_value=None)
    mock_redis.aclose = AsyncMock()
    with patch("eshopeo.api.routers.analytics.aioredis.from_url", return_value=mock_redis):
        r = c.get(
            "/v1/analytics/quota",
            headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
        )
    assert r.status_code == 200
    data = r.json()
    assert data["used"] == 0
    assert data["remaining"] == data["limit"]


def test_quota_status_requires_auth(client):
    c, _, _ = client
    r = c.get("/v1/analytics/quota")
    assert r.status_code == 401
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_quota_status.py -v
```
Expected: 3 FAIL

- [ ] **Step 3: Add `GET /v1/analytics/quota` to `eshopeo/api/routers/analytics.py`**

Read the current analytics.py first to understand existing imports.

Add imports (check for duplicates):
```python
import redis.asyncio as aioredis
from datetime import datetime, timezone
from eshopeo.config import get_settings
```

Add Pydantic model:
```python
class QuotaStatus(BaseModel):
    period: str
    used: int
    limit: int
    remaining: int
```

Add endpoint:
```python
@router.get("/quota", response_model=QuotaStatus)
async def get_quota_status(
    tenant: Tenant = Depends(get_tenant),
) -> QuotaStatus:
    settings = get_settings()
    period = datetime.now(timezone.utc).strftime("%Y-%m")
    key = f"quota:{tenant.id}:{period}"
    redis_client = aioredis.from_url(str(settings.redis_url), decode_responses=True)
    try:
        used_str = await redis_client.get(key)
        used = int(used_str) if used_str else 0
    except Exception:
        used = 0
    finally:
        await redis_client.aclose()
    limit = settings.default_monthly_query_limit
    return QuotaStatus(
        period=period,
        used=used,
        limit=limit,
        remaining=max(0, limit - used),
    )
```

- [ ] **Step 4: Run tests**

```
cd services/core && python -m pytest tests/test_quota_status.py -v
```
Expected: 3 PASS

- [ ] **Step 5: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 157 PASS (154 + 3)

- [ ] **Step 6: Commit**

```
git add eshopeo/api/routers/analytics.py tests/test_quota_status.py
git commit -m "feat: quota status endpoint GET /v1/analytics/quota"
```

---

### Task 4 (P7-4): Full test suite + PROGRESS.md update

**Files:**
- Update: `docs/PROGRESS.md`

- [ ] **Step 1: Run full test suite**

```
cd services/core && python -m pytest -v --tb=short
```
All tests must pass. Fix any failures before updating PROGRESS.md.

- [ ] **Step 2: Update `docs/PROGRESS.md`**

Update status snapshot to Phase 7 complete.

Add Phase 7 section:
```markdown
## Phase 7: Streaming Widget Chat, Search Suggestions & Quota Visibility ✅

All 4 tasks complete.

### Tasks
- [x] Task 1: Streaming widget chat — POST /v1/widget/chat/stream (SSE, v1 full-response-as-stream) (4 tests)
- [x] Task 2: Product title suggestions — suggest_product_titles CRUD + GET /v1/search/suggest (3 tests)
- [x] Task 3: Quota status — GET /v1/analytics/quota reads Redis quota key (3 tests)
- [x] Task 4: Full suite (<N> tests) + PROGRESS.md update
```

Add session log entry:
```
### 2026-06-12 (Phase 7) — Claude Sonnet 4.6
Built Phase 7 streaming and discoverability: SSE streaming chat endpoint (POST /v1/widget/chat/stream) wrapping existing handle_query pipeline and yielding token+done events; product title suggestions (GET /v1/search/suggest via ILIKE prefix query on Product.title); quota status endpoint (GET /v1/analytics/quota reads Redis quota:{tenant_id}:{YYYY-MM} key, returns used/limit/remaining/period). <N> tests total (<M> new Phase 7 + 147 prior). Next: Phase 8.
```

- [ ] **Step 3: Commit**

```
git -C "D:\Dev Projects\ai-ecommerce-master-plugin-beauty" add docs/PROGRESS.md
git -C "D:\Dev Projects\ai-ecommerce-master-plugin-beauty" commit -m "docs: Phase 7 complete — <N> tests pass"
```
