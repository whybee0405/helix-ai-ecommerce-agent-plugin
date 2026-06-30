# Phase 4 — Production Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship CORS for cross-origin widget calls, request ID correlation, orders data sync, monthly quota enforcement, and WooCommerce order webhooks.

**Architecture:** Two new middlewares (CORS via Starlette, RequestId custom); orders CRUD mirrors products pattern (INSERT … ON CONFLICT DO UPDATE); quota uses Redis monthly counter per tenant; order webhook mirrors product webhook pattern.

**Tech Stack:** Python 3.12, FastAPI/Starlette, SQLAlchemy async, Redis asyncio, pytest asyncio_mode=auto

**Test suite baseline:** 99 tests passing at start of Phase 4.

---

### Task 1 (P4-1): CORS + Request ID middleware

**Files:**
- Modify: `services/core/eshopeo/config.py`
- Create: `services/core/eshopeo/api/middleware/request_id.py`
- Modify: `services/core/eshopeo/api/app.py`
- Test: `services/core/tests/test_cors.py`, `services/core/tests/test_request_id.py`

**Context:**
- CORS uses `starlette.middleware.cors.CORSMiddleware` — already a Starlette/FastAPI dependency, no new package needed
- Middleware stack order (outermost → innermost): CORS → RequestId → Quota → RateLimit → routing
- CORS must be outermost so OPTIONS preflight responses are handled before any business logic
- `asyncio_mode = "auto"` — no `@pytest.mark.asyncio`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_cors.py
from fastapi.testclient import TestClient
from eshopeo.api.app import create_app
from tests.conftest import make_test_settings


def test_cors_preflight_allowed():
    settings = make_test_settings(cors_allowed_origins=["https://mystore.com"])
    app = create_app(settings)
    c = TestClient(app)
    r = c.options(
        "/v1/health",
        headers={
            "Origin": "https://mystore.com",
            "Access-Control-Request-Method": "GET",
        },
    )
    assert r.status_code in (200, 204)
    assert "access-control-allow-origin" in r.headers


def test_cors_wildcard_default():
    settings = make_test_settings()
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/health", headers={"Origin": "https://somestore.com"})
    assert "access-control-allow-origin" in r.headers


def test_cors_exposes_request_id():
    settings = make_test_settings()
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/health", headers={"Origin": "https://somestore.com"})
    exposed = r.headers.get("access-control-expose-headers", "")
    assert "X-Request-Id" in exposed
```

```python
# services/core/tests/test_request_id.py
from fastapi.testclient import TestClient
from eshopeo.api.app import create_app
from tests.conftest import make_test_settings


def test_request_id_generated_when_absent():
    settings = make_test_settings()
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/health")
    assert "x-request-id" in r.headers
    assert len(r.headers["x-request-id"]) == 36  # UUID format


def test_request_id_echoed_when_provided():
    settings = make_test_settings()
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/health", headers={"X-Request-Id": "my-trace-id-abc"})
    assert r.headers["x-request-id"] == "my-trace-id-abc"


def test_request_id_unique_per_request():
    settings = make_test_settings()
    app = create_app(settings)
    c = TestClient(app)
    r1 = c.get("/v1/health")
    r2 = c.get("/v1/health")
    assert r1.headers["x-request-id"] != r2.headers["x-request-id"]
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_cors.py tests/test_request_id.py -v
```
Expected: FAIL (CORS headers missing, request-id missing)

- [ ] **Step 3: Add settings fields**

In `services/core/eshopeo/config.py`, add to `Settings`:
```python
cors_allowed_origins: list[str] = ["*"]
default_monthly_query_limit: int = 10_000
```

- [ ] **Step 4: Create `eshopeo/api/middleware/request_id.py`**

```python
import uuid
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request


class RequestIdMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        request_id = request.headers.get("X-Request-Id") or str(uuid.uuid4())
        response = await call_next(request)
        response.headers["X-Request-Id"] = request_id
        return response
```

- [ ] **Step 5: Register both middlewares in `app.py`**

In `create_app()`, add BEFORE the existing `app.add_middleware(RateLimitMiddleware, ...)` call:
```python
from starlette.middleware.cors import CORSMiddleware
from eshopeo.api.middleware.request_id import RequestIdMiddleware

app.add_middleware(
    CORSMiddleware,
    allow_origins=s.cors_allowed_origins,
    allow_methods=["GET", "POST", "PATCH"],
    allow_headers=["Authorization", "Content-Type", "X-eShopeo-Tenant-Key",
                   "X-eShopeo-Provision-Key", "X-Request-Id"],
    expose_headers=["X-Request-Id"],
)
app.add_middleware(RequestIdMiddleware)
```

Note: `add_middleware` is a stack — the LAST one registered is the OUTERMOST. So register RequestId first, then CORS (so CORS ends up outermost).

- [ ] **Step 6: Run tests**

```
cd services/core && python -m pytest tests/test_cors.py tests/test_request_id.py -v
```
Expected: 6 PASS

- [ ] **Step 7: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 105 PASS

- [ ] **Step 8: Commit**

```
git add eshopeo/config.py eshopeo/api/middleware/request_id.py eshopeo/api/app.py \
        tests/test_cors.py tests/test_request_id.py
git commit -m "feat: CORS middleware and X-Request-Id correlation header"
```

---

### Task 2 (P4-2): Orders sync endpoint

**Files:**
- Create: `services/core/eshopeo/db/crud/orders.py`
- Modify: `services/core/eshopeo/api/routers/sync.py`
- Test: `services/core/tests/test_orders_sync.py`

**Context:**
- `CanonicalOrder` in `eshopeo/connectors/models.py` has: `tenant_id, platform, platform_id, customer_platform_id, total_minor, currency, status, line_items, placed_at`
- `Order` model (`eshopeo/db/models.py`) unique constraint: `uq_order_tenant_platform` on `(tenant_id, platform_id)`
- `Order.customer_id` is nullable — lookup Customer by `customer_platform_id` if provided
- `upsert_product` in `crud/products.py` shows the exact INSERT … ON CONFLICT pattern to follow
- Auth: `get_tenant` dep (X-eShopeo-Tenant-Key header)
- `asyncio_mode = "auto"` — no `@pytest.mark.asyncio`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_orders_sync.py
from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db, get_tenant
from eshopeo.db.models import Order, Tenant
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

    upserted = Order(tenant_id=tenant.id, platform_id="wc-1",
                     total_minor=10000, currency="ZAR", status="processing",
                     line_items=[], placed_at=datetime.now(timezone.utc))
    upserted.id = uuid4()

    app.dependency_overrides[get_db] = lambda: mock_db
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("eshopeo.api.routers.sync.upsert_order", AsyncMock(return_value=upserted)):
        yield TestClient(app), tenant, settings


ORDER_PAYLOAD = {
    "tenant_id": "00000000-0000-0000-0000-000000000001",
    "platform": "woocommerce",
    "platform_id": "wc-1",
    "total_minor": 10000,
    "currency": "ZAR",
    "status": "processing",
    "line_items": [{"product_id": "p1", "quantity": 2}],
    "placed_at": "2026-06-11T10:00:00+00:00",
}


def test_sync_orders_returns_synced_count(client):
    c, tenant, _ = client
    r = c.post(
        "/v1/sync/orders",
        json={"orders": [ORDER_PAYLOAD]},
        headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
    )
    assert r.status_code == 200
    assert r.json()["synced"] == 1
    assert r.json()["failed"] == 0


def test_sync_orders_empty_list(client):
    c, tenant, _ = client
    r = c.post(
        "/v1/sync/orders",
        json={"orders": []},
        headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
    )
    assert r.status_code == 200
    assert r.json()["synced"] == 0


def test_sync_orders_requires_auth(client):
    c, _, _ = client
    r = c.post("/v1/sync/orders", json={"orders": [ORDER_PAYLOAD]})
    assert r.status_code == 401


def test_sync_orders_handles_upsert_error(client):
    c, tenant, _ = client
    with patch("eshopeo.api.routers.sync.upsert_order",
               AsyncMock(side_effect=Exception("DB error"))):
        r = c.post(
            "/v1/sync/orders",
            json={"orders": [ORDER_PAYLOAD]},
            headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
        )
    assert r.status_code == 200
    assert r.json()["failed"] == 1
    assert len(r.json()["errors"]) == 1
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_orders_sync.py -v
```
Expected: FAIL (endpoint doesn't exist)

- [ ] **Step 3: Create `services/core/eshopeo/db/crud/orders.py`**

```python
from uuid import UUID, uuid4

from sqlalchemy import select
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.db.models import Customer, Order


async def upsert_order(session: AsyncSession, order: Order) -> Order:
    stmt = (
        insert(Order)
        .values(
            id=order.id,
            tenant_id=order.tenant_id,
            platform_id=order.platform_id,
            customer_id=order.customer_id,
            total_minor=order.total_minor,
            currency=order.currency,
            status=order.status,
            line_items=order.line_items,
            placed_at=order.placed_at,
        )
        .on_conflict_do_update(
            constraint="uq_order_tenant_platform",
            set_=dict(
                customer_id=order.customer_id,
                total_minor=order.total_minor,
                currency=order.currency,
                status=order.status,
                line_items=order.line_items,
                placed_at=order.placed_at,
            ),
        )
        .returning(Order)
    )
    result = await session.execute(stmt)
    return result.scalar_one()


async def get_customer_id_by_platform_id(
    session: AsyncSession, tenant_id: UUID, platform_id: str
) -> UUID | None:
    result = await session.execute(
        select(Customer.id).where(
            Customer.tenant_id == tenant_id,
            Customer.platform_id == platform_id,
        )
    )
    return result.scalar_one_or_none()
```

- [ ] **Step 4: Add orders sync endpoint to `sync.py`**

Add these imports (if not already present):
```python
from eshopeo.connectors.models import CanonicalOrder
from eshopeo.db.crud.orders import get_customer_id_by_platform_id, upsert_order
from eshopeo.db.models import Order
```

Add the endpoint:
```python
class OrderSyncRequest(BaseModel):
    orders: list[CanonicalOrder]


class OrderSyncResponse(BaseModel):
    synced: int
    failed: int
    errors: list[str]


@router.post("/orders", response_model=OrderSyncResponse)
async def sync_orders(
    body: OrderSyncRequest,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> OrderSyncResponse:
    synced = 0
    failed = 0
    errors: list[str] = []

    for co in body.orders:
        try:
            customer_id = None
            if co.customer_platform_id:
                customer_id = await get_customer_id_by_platform_id(
                    db, tenant.id, co.customer_platform_id
                )
            order = Order(
                tenant_id=tenant.id,
                platform_id=co.platform_id,
                customer_id=customer_id,
                total_minor=co.total_minor,
                currency=co.currency,
                status=co.status,
                line_items=co.line_items,
                placed_at=co.placed_at,
            )
            await upsert_order(db, order)
            synced += 1
        except Exception as exc:
            logger.warning("sync_order_error", platform_id=co.platform_id, error=str(exc))
            errors.append(f"order {co.platform_id}: {exc}")
            failed += 1

    await db.commit()
    return OrderSyncResponse(synced=synced, failed=failed, errors=errors)
```

- [ ] **Step 5: Run tests**

```
cd services/core && python -m pytest tests/test_orders_sync.py -v
```
Expected: 4 PASS

- [ ] **Step 6: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 109 PASS

- [ ] **Step 7: Commit**

```
git add eshopeo/db/crud/orders.py eshopeo/api/routers/sync.py tests/test_orders_sync.py
git commit -m "feat: POST /v1/sync/orders closes the order data loop"
```

---

### Task 3 (P4-3): Monthly quota middleware

**Files:**
- Create: `services/core/eshopeo/api/middleware/quota.py`
- Modify: `services/core/eshopeo/api/app.py`
- Test: `services/core/tests/test_quota.py`

**Context:**
- `Settings.default_monthly_query_limit` (added in Task 1) — default 10_000
- Widget paths to throttle: `/v1/widget/chat` and `/v1/widget/routine` (same as rate limiter)
- Redis key: `quota:{tenant_id}:{YYYY-MM}` — e.g. `quota:abc-123:2026-06`
- TTL: 32 days (covers month boundary)
- Extract tenant_id from unverified JWT claims (same as rate_limit.py: `_extract_tenant_id`)
- Fails open on Redis error; no tenant_id = pass through
- Returns 429 with header `X-Quota-Exceeded: monthly` when over limit
- `asyncio_mode = "auto"` — no `@pytest.mark.asyncio`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_quota.py
from datetime import datetime
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db, get_tenant, get_widget_tenant
from eshopeo.db.models import Tenant
from tests.conftest import make_test_settings


def _make_jwt(tenant_id: str, settings) -> str:
    from jose import jwt
    import time
    payload = {"tenant_id": tenant_id, "exp": int(time.time()) + 900}
    return jwt.encode(payload, settings.session_secret.get_secret_value(), algorithm="HS256")


@pytest.fixture
def tenant():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc")
    t.id = uuid4()
    t.public_key = uuid4()
    return t


def test_quota_allows_request_under_limit():
    settings = make_test_settings(default_monthly_query_limit=100)
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)
    app.dependency_overrides[get_db] = lambda: mock_db

    mock_redis = AsyncMock()
    mock_redis.incr = AsyncMock(return_value=50)
    mock_redis.expire = AsyncMock()

    with patch("eshopeo.api.middleware.quota.aioredis.from_url", return_value=mock_redis):
        app2 = create_app(settings)
        token = _make_jwt("some-tenant", settings)
        c = TestClient(app2)
        r = c.post(
            "/v1/widget/chat",
            json={"query": "hi"},
            headers={"Authorization": f"Bearer {token}"},
        )
    assert r.status_code != 429


def test_quota_blocks_request_over_limit(tenant):
    settings = make_test_settings(default_monthly_query_limit=10)
    app = create_app(settings)

    mock_redis = AsyncMock()
    mock_redis.incr = AsyncMock(return_value=11)
    mock_redis.expire = AsyncMock()

    with patch("eshopeo.api.middleware.quota.aioredis.from_url", return_value=mock_redis):
        app2 = create_app(settings)
        token = _make_jwt(str(tenant.id), settings)
        c = TestClient(app2)
        r = c.post(
            "/v1/widget/chat",
            json={"query": "hi"},
            headers={"Authorization": f"Bearer {token}"},
        )
    assert r.status_code == 429
    assert r.headers.get("X-Quota-Exceeded") == "monthly"


def test_quota_passes_through_non_widget_paths():
    settings = make_test_settings(default_monthly_query_limit=1)
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/health")
    assert r.status_code != 429


def test_quota_fails_open_on_redis_error():
    settings = make_test_settings(default_monthly_query_limit=10)

    mock_redis = AsyncMock()
    mock_redis.incr = AsyncMock(side_effect=Exception("Redis down"))

    with patch("eshopeo.api.middleware.quota.aioredis.from_url", return_value=mock_redis):
        app = create_app(settings)
        token = _make_jwt("some-tenant", settings)
        c = TestClient(app)
        r = c.post(
            "/v1/widget/chat",
            json={"query": "hi"},
            headers={"Authorization": f"Bearer {token}"},
        )
    assert r.status_code != 429
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_quota.py -v
```
Expected: FAIL (quota middleware doesn't exist)

- [ ] **Step 3: Create `eshopeo/api/middleware/quota.py`**

```python
from datetime import datetime, timezone

import redis.asyncio as aioredis
import structlog
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import JSONResponse

from eshopeo.config import Settings

logger = structlog.get_logger(__name__)

_WIDGET_PATHS = {"/v1/widget/chat", "/v1/widget/routine"}
_TTL_SECONDS = 32 * 24 * 3600


def _extract_tenant_id(authorization: str | None) -> str | None:
    if not authorization or not authorization.startswith("Bearer "):
        return None
    token = authorization.removeprefix("Bearer ")
    try:
        from jose import jwt as _jwt
        payload = _jwt.get_unverified_claims(token)
        return payload.get("tenant_id")
    except Exception:
        return None


class QuotaMiddleware(BaseHTTPMiddleware):
    def __init__(self, app, settings: Settings) -> None:
        super().__init__(app)
        self._redis = aioredis.from_url(str(settings.redis_url), decode_responses=True)
        self._limit = settings.default_monthly_query_limit

    async def dispatch(self, request: Request, call_next):
        if request.url.path not in _WIDGET_PATHS:
            return await call_next(request)

        tenant_id = _extract_tenant_id(request.headers.get("authorization"))
        if tenant_id is None:
            return await call_next(request)

        month_key = datetime.now(timezone.utc).strftime("%Y-%m")
        key = f"quota:{tenant_id}:{month_key}"
        try:
            count = await self._redis.incr(key)
            if count == 1:
                await self._redis.expire(key, _TTL_SECONDS)
            if count > self._limit:
                logger.warning("quota_exceeded", tenant_id=tenant_id, count=count, limit=self._limit)
                return JSONResponse(
                    status_code=429,
                    content={"detail": "Monthly query limit exceeded"},
                    headers={"X-Quota-Exceeded": "monthly"},
                )
        except Exception:
            pass

        return await call_next(request)
```

- [ ] **Step 4: Register QuotaMiddleware in `app.py`**

In `create_app()`, add QuotaMiddleware registration BETWEEN RequestId and RateLimit:
```python
from eshopeo.api.middleware.quota import QuotaMiddleware
app.add_middleware(QuotaMiddleware, settings=s)
```

Final middleware registration order (remember: last registered = outermost):
```python
app.add_middleware(RateLimitMiddleware, settings=s)   # registered 1st → innermost
app.add_middleware(QuotaMiddleware, settings=s)        # registered 2nd
app.add_middleware(RequestIdMiddleware)                # registered 3rd
app.add_middleware(CORSMiddleware, ...)               # registered 4th → outermost
```

- [ ] **Step 5: Run tests**

```
cd services/core && python -m pytest tests/test_quota.py -v
```
Expected: 4 PASS

- [ ] **Step 6: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 113 PASS

- [ ] **Step 7: Commit**

```
git add eshopeo/api/middleware/quota.py eshopeo/api/app.py tests/test_quota.py
git commit -m "feat: monthly quota middleware (Redis counter, 429 on limit)"
```

---

### Task 4 (P4-4): WooCommerce orders webhook

**Files:**
- Modify: `services/core/eshopeo/api/routers/webhooks.py`
- Test: `services/core/tests/test_woocommerce_order_webhook.py`

**Context:**
- `_verify_wc_signature(body, signature, secret)` already in `webhooks.py` — reuse it
- `get_tenant_by_id`, `decrypt_credentials`, `get_settings` already imported in `webhooks.py`
- `CanonicalOrder` in `eshopeo/connectors/models.py`
- `upsert_order` in `eshopeo/db/crud/orders.py` (created in Task 2)
- WooCommerce order payload structure: `{"id": 123, "customer_id": 456, "total": "250.00", "currency": "ZAR", "status": "processing", "line_items": [...], "date_created": "2026-06-11T10:00:00"}`
- `asyncio_mode = "auto"`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_woocommerce_order_webhook.py
import base64
import hashlib
import hmac
import json
from datetime import datetime, timezone
from unittest.mock import AsyncMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db
from eshopeo.db.models import Order, Tenant
from tests.conftest import make_test_settings


SECRET = "wc-secret-123"


def _sign(body: bytes) -> str:
    return base64.b64encode(
        hmac.new(SECRET.encode(), body, hashlib.sha256).digest()
    ).decode()


@pytest.fixture
def tenant():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc")
    t.id = uuid4()
    t.public_key = uuid4()
    return t


WC_ORDER = {
    "id": 999,
    "customer_id": 0,
    "total": "250.00",
    "currency": "ZAR",
    "status": "processing",
    "line_items": [{"product_id": 1, "quantity": 1}],
    "date_created": "2026-06-11T10:00:00",
}


@pytest.fixture
def client(tenant):
    settings = make_test_settings()
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)
    mock_db.commit = AsyncMock()

    upserted = Order(tenant_id=tenant.id, platform_id="999",
                     total_minor=25000, currency="ZAR", status="processing",
                     line_items=[], placed_at=datetime.now(timezone.utc))
    upserted.id = uuid4()

    app.dependency_overrides[get_db] = lambda: mock_db

    with (
        patch("eshopeo.api.routers.webhooks.get_tenant_by_id", AsyncMock(return_value=tenant)),
        patch("eshopeo.api.routers.webhooks.decrypt_credentials",
              return_value={"webhook_secret": SECRET}),
        patch("eshopeo.api.routers.webhooks.upsert_order", AsyncMock(return_value=upserted)),
    ):
        yield TestClient(app), tenant


def test_wc_order_webhook_accepts_valid_payload(client):
    c, tenant = client
    body = json.dumps(WC_ORDER).encode()
    r = c.post(
        "/v1/webhooks/orders",
        content=body,
        headers={
            "X-eShopeo-Tenant-Id": str(tenant.id),
            "X-WC-Webhook-Signature": _sign(body),
            "Content-Type": "application/json",
        },
    )
    assert r.status_code == 200
    assert r.json()["status"] == "ok"


def test_wc_order_webhook_rejects_bad_signature(client):
    c, tenant = client
    body = json.dumps(WC_ORDER).encode()
    r = c.post(
        "/v1/webhooks/orders",
        content=body,
        headers={
            "X-eShopeo-Tenant-Id": str(tenant.id),
            "X-WC-Webhook-Signature": "bad-sig",
            "Content-Type": "application/json",
        },
    )
    assert r.status_code == 401


def test_wc_order_webhook_rejects_unknown_tenant(client):
    c, _ = client
    body = json.dumps(WC_ORDER).encode()
    with patch("eshopeo.api.routers.webhooks.get_tenant_by_id", AsyncMock(return_value=None)):
        r = c.post(
            "/v1/webhooks/orders",
            content=body,
            headers={
                "X-eShopeo-Tenant-Id": str(uuid4()),
                "X-WC-Webhook-Signature": _sign(body),
                "Content-Type": "application/json",
            },
        )
    assert r.status_code == 401
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_woocommerce_order_webhook.py -v
```
Expected: FAIL (404 — route doesn't exist)

- [ ] **Step 3: Add order webhook to `webhooks.py`**

Add these imports to `webhooks.py` (check for duplicates first):
```python
from datetime import datetime
from eshopeo.db.crud.orders import upsert_order
from eshopeo.db.models import Order
```

Add the new endpoint and translator function:
```python
def _translate_wc_order(payload: dict[str, Any], tenant_id: UUID) -> Order:
    customer_id_raw = payload.get("customer_id")
    placed_raw = payload.get("date_created", "")
    try:
        placed_at = datetime.fromisoformat(placed_raw)
    except (ValueError, TypeError):
        placed_at = datetime.utcnow()

    return Order(
        tenant_id=tenant_id,
        platform_id=str(payload["id"]),
        customer_id=None,
        total_minor=int(round(float(payload.get("total", "0")) * 100)),
        currency=payload.get("currency", "ZAR"),
        status=payload.get("status", "unknown"),
        line_items=payload.get("line_items", []),
        placed_at=placed_at,
    )


@router.post("/orders")
async def order_webhook(
    request: Request,
    x_eshopeo_tenant_id: str = Header(...),
    x_wc_webhook_signature: str = Header(...),
    db: AsyncSession = Depends(get_db),
) -> dict[str, str]:
    body = await request.body()

    try:
        tenant_id = UUID(x_eshopeo_tenant_id)
    except ValueError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid tenant ID")

    tenant = await get_tenant_by_id(db, tenant_id)
    if tenant is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Unknown tenant")

    settings = get_settings()
    creds = decrypt_credentials(tenant.credentials_enc, settings.credential_encryption_key.get_secret_value())
    webhook_secret = creds.get("webhook_secret", "")

    if not _verify_wc_signature(body, x_wc_webhook_signature, webhook_secret):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid signature")

    payload: dict[str, Any] = json.loads(body)
    order = _translate_wc_order(payload, tenant_id)
    await upsert_order(db, order)
    await db.commit()
    return {"status": "ok"}
```

- [ ] **Step 4: Run tests**

```
cd services/core && python -m pytest tests/test_woocommerce_order_webhook.py -v
```
Expected: 3 PASS

- [ ] **Step 5: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 116 PASS

- [ ] **Step 6: Commit**

```
git add eshopeo/api/routers/webhooks.py eshopeo/db/crud/orders.py \
        tests/test_woocommerce_order_webhook.py
git commit -m "feat: WooCommerce order webhook POST /v1/webhooks/orders"
```

---

### Task 5 (P4-5): Clean up dead code + fix shopify webhook pack routing

**Files:**
- Modify: `services/core/eshopeo/api/routers/webhooks.py` — remove dead `pack = default_pack()` line
- Modify: `services/core/eshopeo/api/routers/shopify_webhooks.py` — replace `default_pack()` with `get_pack_for_tenant(tenant)`

**Context:**
- In `webhooks.py` (WooCommerce product webhook): `pack = default_pack()` is assigned but never used — dead code, remove it
- In `shopify_webhooks.py`: `pack = default_pack()` is used to access `pack.product_schema` for validation — should use `get_pack_for_tenant(tenant)` since `tenant` is already loaded at that point
- No new tests needed (existing webhook tests cover behaviour; this is a refactor)
- After removing the dead code in `webhooks.py`, also remove the unused `default_pack` import if it becomes unused there

- [ ] **Step 1: Read both files to see exact current state**

Read `eshopeo/api/routers/webhooks.py` and `eshopeo/api/routers/shopify_webhooks.py`.

- [ ] **Step 2: Fix `webhooks.py`**

Remove the line `pack = default_pack()` from the `product_webhook` function. Also remove `from eshopeo.packs.registry import default_pack` if that import is now unused.

- [ ] **Step 3: Fix `shopify_webhooks.py`**

Change `from eshopeo.packs.registry import default_pack` → `from eshopeo.packs.registry import get_pack_for_tenant`

Replace `pack = default_pack()` with `pack = get_pack_for_tenant(tenant)`.

- [ ] **Step 4: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 116 PASS (no test count change — this is a refactor)

- [ ] **Step 5: Commit**

```
git add eshopeo/api/routers/webhooks.py eshopeo/api/routers/shopify_webhooks.py
git commit -m "refactor: remove dead pack call in WC webhook; shopify webhook uses get_pack_for_tenant"
```

---

### Task 6 (P4-6): Full test suite + PROGRESS.md

**Files:**
- Update: `docs/PROGRESS.md`

**Context:**
- Target: ~116 tests passing (99 baseline + 17 new in Phase 4)
- `asyncio_mode = "auto"` in pyproject.toml — confirm no `@pytest.mark.asyncio` was introduced

- [ ] **Step 1: Run full test suite**

```
cd services/core && python -m pytest -v --tb=short
```
All tests must pass. If any fail, fix before updating PROGRESS.md.

- [ ] **Step 2: Update PROGRESS.md**

Update the status snapshot:
```markdown
## Status snapshot
- **Current phase:** Phase 4 — Production Hardening
- **Overall:** complete
- **Last updated:** 2026-06-11
- **Last worked by:** Claude Sonnet 4.6
- **Build health:** green — <N>/<N> tests pass
```

Add Phase 4 tasks section:
```markdown
## Phase 4 — Production Hardening

All 6 tasks complete.

### Tasks
- [x] Task 1: CORS middleware + X-Request-Id correlation header
- [x] Task 2: Orders sync endpoint (`POST /v1/sync/orders`)
- [x] Task 3: Monthly quota middleware (Redis counter, 429 on limit)
- [x] Task 4: WooCommerce orders webhook (`POST /v1/webhooks/orders`)
- [x] Task 5: Dead code cleanup + Shopify webhook uses `get_pack_for_tenant`
- [x] Task 6: Full test suite + PROGRESS.md
```

Add session log entry before Phase 3 entry.

- [ ] **Step 3: Commit**

```
git add docs/PROGRESS.md
git commit -m "docs: Phase 4 complete — <N> tests pass"
```
