# Phase 5 — Shopify Orders, Admin Stats & Pack API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the Shopify order data loop; add operator-facing admin stats and pack discovery; enhance search with category filtering.

**Architecture:** Shopify order webhook mirrors products webhook pattern; admin stats uses cross-tenant COUNT/SUM queries (no tenant scope); packs API reads in-memory registry (zero DB cost); search category filter uses JSONB `@>` containment on Product.categories.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy async, pytest asyncio_mode=auto

**Test suite baseline:** 119 tests passing at start of Phase 5.

---

### Task 1 (P5-1): Shopify orders webhook

**Files:**
- Modify: `services/core/eshopeo/connectors/shopify.py` — add `translate_shopify_order()`
- Modify: `services/core/eshopeo/api/routers/shopify_webhooks.py` — add `POST /v1/webhooks/shopify/orders`
- Test: `services/core/tests/test_shopify_order_webhook.py`

**Context:**
- `verify_shopify_webhook(body, hmac_header, secret)` already in `eshopeo/connectors/shopify.py` — Shopify uses base64-encoded HMAC-SHA256 (different from WooCommerce which uses hex)
- `upsert_order` in `eshopeo/db/crud/orders.py` (from Phase 4)
- `CanonicalOrder` in `eshopeo/connectors/models.py`
- `shopify_webhooks.py` already imports: `verify_shopify_webhook`, `decrypt_credentials`, `get_tenant_by_id`, `get_pack_for_tenant`, `json`, `UUID`, `structlog`, `get_db`, `Request`, `Header`, `HTTPException`, `status`
- Shopify order payload: `{"id": 12345, "customer": {"id": 67890}, "total_price": "250.00", "currency": "USD", "financial_status": "paid", "line_items": [...], "created_at": "2026-06-11T10:00:00+00:00"}`
- `asyncio_mode = "auto"` — never `@pytest.mark.asyncio`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_shopify_order_webhook.py
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

SECRET = "shopify-secret-abc"


def _sign(body: bytes) -> str:
    return base64.b64encode(
        hmac.new(SECRET.encode(), body, hashlib.sha256).digest()
    ).decode()


@pytest.fixture
def tenant():
    t = Tenant(name="x", platform="shopify", store_url="https://x.myshopify.com",
               credentials_enc=b"enc")
    t.id = uuid4()
    t.public_key = uuid4()
    return t


SHOPIFY_ORDER = {
    "id": 12345,
    "customer": {"id": 67890},
    "total_price": "250.00",
    "currency": "USD",
    "financial_status": "paid",
    "line_items": [{"product_id": 1, "quantity": 2, "title": "Toner"}],
    "created_at": "2026-06-11T10:00:00+00:00",
}


@pytest.fixture
def client(tenant):
    settings = make_test_settings()
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)
    mock_db.commit = AsyncMock()

    upserted = Order(tenant_id=tenant.id, platform_id="12345",
                     total_minor=25000, currency="USD", status="paid",
                     line_items=[], placed_at=datetime.now(timezone.utc))
    upserted.id = uuid4()

    app.dependency_overrides[get_db] = lambda: mock_db

    with (
        patch("eshopeo.api.routers.shopify_webhooks.get_tenant_by_id",
              AsyncMock(return_value=tenant)),
        patch("eshopeo.api.routers.shopify_webhooks.decrypt_credentials",
              return_value={"webhook_secret": SECRET}),
        patch("eshopeo.api.routers.shopify_webhooks.upsert_order",
              AsyncMock(return_value=upserted)),
    ):
        yield TestClient(app), tenant


def test_shopify_order_webhook_accepts_valid_payload(client):
    c, tenant = client
    body = json.dumps(SHOPIFY_ORDER).encode()
    r = c.post(
        "/v1/webhooks/shopify/orders",
        content=body,
        headers={
            "X-eShopeo-Tenant-Id": str(tenant.id),
            "X-Shopify-Hmac-Sha256": _sign(body),
            "Content-Type": "application/json",
        },
    )
    assert r.status_code == 200
    assert r.json()["status"] == "ok"


def test_shopify_order_webhook_rejects_bad_signature(client):
    c, tenant = client
    body = json.dumps(SHOPIFY_ORDER).encode()
    r = c.post(
        "/v1/webhooks/shopify/orders",
        content=body,
        headers={
            "X-eShopeo-Tenant-Id": str(tenant.id),
            "X-Shopify-Hmac-Sha256": "bad-sig",
            "Content-Type": "application/json",
        },
    )
    assert r.status_code == 401


def test_shopify_order_webhook_rejects_unknown_tenant(client):
    c, _ = client
    body = json.dumps(SHOPIFY_ORDER).encode()
    with patch("eshopeo.api.routers.shopify_webhooks.get_tenant_by_id",
               AsyncMock(return_value=None)):
        r = c.post(
            "/v1/webhooks/shopify/orders",
            content=body,
            headers={
                "X-eShopeo-Tenant-Id": str(uuid4()),
                "X-Shopify-Hmac-Sha256": _sign(body),
                "Content-Type": "application/json",
            },
        )
    assert r.status_code == 401


def test_shopify_order_translates_fields():
    from eshopeo.connectors.shopify import translate_shopify_order
    tenant_id = uuid4()
    order = translate_shopify_order(SHOPIFY_ORDER, tenant_id)
    assert order.platform == "shopify"
    assert order.platform_id == "12345"
    assert order.total_minor == 25000
    assert order.customer_platform_id == "67890"
    assert order.status == "paid"
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_shopify_order_webhook.py -v
```
Expected: 4 FAIL

- [ ] **Step 3: Add `translate_shopify_order` to `connectors/shopify.py`**

Add these imports at top of `shopify.py`:
```python
from datetime import datetime, timezone
from eshopeo.connectors.models import CanonicalOrder
```

Add function:
```python
def translate_shopify_order(payload: dict, tenant_id: UUID) -> CanonicalOrder:
    customer = payload.get("customer") or {}
    customer_platform_id = str(customer["id"]) if customer.get("id") else None
    total_price = payload.get("total_price", "0") or "0"
    try:
        total_minor = int(float(total_price) * 100)
    except (ValueError, TypeError):
        total_minor = 0
    placed_raw = payload.get("created_at", "")
    try:
        placed_at = datetime.fromisoformat(placed_raw)
    except (ValueError, TypeError):
        placed_at = datetime.now(timezone.utc)
    return CanonicalOrder(
        tenant_id=tenant_id,
        platform="shopify",
        platform_id=str(payload.get("id", "")),
        customer_platform_id=customer_platform_id,
        total_minor=total_minor,
        currency=payload.get("currency", "USD"),
        status=payload.get("financial_status", "unknown"),
        line_items=payload.get("line_items", []),
        placed_at=placed_at,
    )
```

- [ ] **Step 4: Add orders endpoint to `shopify_webhooks.py`**

Add these imports (check for duplicates):
```python
from eshopeo.connectors.shopify import translate_shopify_order, verify_shopify_webhook
from eshopeo.db.crud.orders import upsert_order
from eshopeo.db.models import Order
```

Add endpoint:
```python
@router.post("/orders")
async def shopify_order_webhook(
    request: Request,
    x_eshopeo_tenant_id: str | None = Header(default=None),
    x_shopify_hmac_sha256: str | None = Header(default=None),
    db: AsyncSession = Depends(get_db),
):
    if x_eshopeo_tenant_id is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Missing tenant ID")
    if x_shopify_hmac_sha256 is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Missing HMAC header")

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

    body = await request.body()
    if not verify_shopify_webhook(body, x_shopify_hmac_sha256, webhook_secret):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid webhook signature")

    try:
        payload = json.loads(body)
    except json.JSONDecodeError:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid JSON payload")

    co = translate_shopify_order(payload, tenant_id)
    from eshopeo.db.crud.orders import get_customer_id_by_platform_id
    customer_id = None
    if co.customer_platform_id:
        customer_id = await get_customer_id_by_platform_id(db, tenant_id, co.customer_platform_id)

    order = Order(
        tenant_id=tenant_id,
        platform_id=co.platform_id,
        customer_id=customer_id,
        total_minor=co.total_minor,
        currency=co.currency,
        status=co.status,
        line_items=co.line_items,
        placed_at=co.placed_at,
    )
    await upsert_order(db, order)
    await db.commit()
    return {"status": "ok"}
```

- [ ] **Step 5: Run tests**

```
cd services/core && python -m pytest tests/test_shopify_order_webhook.py -v
```
Expected: 4 PASS

- [ ] **Step 6: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 123 PASS

- [ ] **Step 7: Commit**

```
git add eshopeo/connectors/shopify.py eshopeo/api/routers/shopify_webhooks.py \
        tests/test_shopify_order_webhook.py
git commit -m "feat: Shopify orders webhook + translate_shopify_order()"
```

---

### Task 2 (P5-2): Admin platform stats

**Files:**
- Create: `services/core/eshopeo/db/crud/admin.py`
- Create: `services/core/eshopeo/api/routers/admin.py`
- Modify: `services/core/eshopeo/api/app.py`
- Test: `services/core/tests/test_admin_stats.py`

**Context:**
- Auth: `X-eShopeo-Provision-Key` — use the same `_auth_provision_key` dep from `tenants.py` BUT don't import it from there (define inline or replicate the dep). Actually, just replicate the 4-line dep inline — simpler than cross-router import.
- Cross-tenant queries: all COUNT/SUM queries have NO tenant_id filter
- `UsageEvent` table: `cost_usd`, `created_at` columns; use `func.count`, `func.sum` from sqlalchemy
- `asyncio_mode = "auto"` — no `@pytest.mark.asyncio`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_admin_stats.py
from datetime import datetime, timezone
from unittest.mock import AsyncMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db
from tests.conftest import make_test_settings


@pytest.fixture
def client():
    settings = make_test_settings()
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)
    app.dependency_overrides[get_db] = lambda: mock_db
    return TestClient(app), settings


MOCK_STATS = {
    "total_tenants": 5,
    "total_products": 200,
    "total_customers": 50,
    "queries_this_month": 1000,
    "cost_this_month_usd": 2.50,
}


def test_admin_stats_returns_data(client):
    c, settings = client
    with patch("eshopeo.api.routers.admin.get_platform_stats",
               AsyncMock(return_value=MOCK_STATS)):
        r = c.get(
            "/v1/admin/stats",
            headers={"X-eShopeo-Provision-Key": settings.provision_key.get_secret_value()},
        )
    assert r.status_code == 200
    data = r.json()
    assert data["total_tenants"] == 5
    assert data["total_products"] == 200
    assert "queries_this_month" in data


def test_admin_stats_401_bad_key(client):
    c, _ = client
    r = c.get("/v1/admin/stats", headers={"X-eShopeo-Provision-Key": "wrong"})
    assert r.status_code == 401


def test_admin_stats_401_missing_key(client):
    c, _ = client
    r = c.get("/v1/admin/stats")
    assert r.status_code == 401
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_admin_stats.py -v
```
Expected: 3 FAIL (route doesn't exist)

- [ ] **Step 3: Create `eshopeo/db/crud/admin.py`**

```python
from datetime import datetime, timezone

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.db.models import Customer, Product, Tenant, UsageEvent


async def get_platform_stats(
    session: AsyncSession,
    month_start: datetime,
    month_end: datetime,
) -> dict:
    tenant_count = (
        await session.execute(select(func.count(Tenant.id)))
    ).scalar_one()

    product_count = (
        await session.execute(select(func.count(Product.id)))
    ).scalar_one()

    customer_count = (
        await session.execute(select(func.count(Customer.id)))
    ).scalar_one()

    usage_row = (
        await session.execute(
            select(
                func.count(UsageEvent.id).label("total"),
                func.sum(UsageEvent.cost_usd).label("cost"),
            ).where(
                UsageEvent.created_at >= month_start,
                UsageEvent.created_at <= month_end,
            )
        )
    ).one()

    return {
        "total_tenants": tenant_count,
        "total_products": product_count,
        "total_customers": customer_count,
        "queries_this_month": usage_row.total or 0,
        "cost_this_month_usd": round(float(usage_row.cost or 0), 6),
    }
```

- [ ] **Step 4: Create `eshopeo/api/routers/admin.py`**

```python
from datetime import date, datetime, timezone
from typing import Annotated

from fastapi import APIRouter, Depends, Header, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db
from eshopeo.config import get_settings
from eshopeo.db.crud.admin import get_platform_stats

router = APIRouter(prefix="/v1/admin", tags=["admin"])


class PlatformStats(BaseModel):
    total_tenants: int
    total_products: int
    total_customers: int
    queries_this_month: int
    cost_this_month_usd: float


def _auth_provision(
    x_eshopeo_provision_key: Annotated[str | None, Header()] = None,
) -> str:
    settings = get_settings()
    if x_eshopeo_provision_key != settings.provision_key.get_secret_value():
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid provision key")
    return x_eshopeo_provision_key


@router.get("/stats", response_model=PlatformStats)
async def admin_stats(
    _: str = Depends(_auth_provision),
    db: AsyncSession = Depends(get_db),
) -> PlatformStats:
    today = date.today()
    month_start = datetime(today.year, today.month, 1, tzinfo=timezone.utc)
    month_end = datetime.now(timezone.utc)
    stats = await get_platform_stats(db, month_start, month_end)
    return PlatformStats(**stats)
```

- [ ] **Step 5: Register admin router in `app.py`**

Add after the analytics router block:
```python
from eshopeo.api.routers import admin
app.include_router(admin.router)
```

- [ ] **Step 6: Run tests**

```
cd services/core && python -m pytest tests/test_admin_stats.py -v
```
Expected: 3 PASS

- [ ] **Step 7: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 126 PASS

- [ ] **Step 8: Commit**

```
git add eshopeo/db/crud/admin.py eshopeo/api/routers/admin.py eshopeo/api/app.py \
        tests/test_admin_stats.py
git commit -m "feat: admin platform stats endpoint GET /v1/admin/stats"
```

---

### Task 3 (P5-3): Pack listing API

**Files:**
- Create: `services/core/eshopeo/api/routers/packs.py`
- Modify: `services/core/eshopeo/api/app.py`
- Test: `services/core/tests/test_packs_endpoint.py`

**Context:**
- `_registry: dict[str, LoadedPack]` in `eshopeo.packs.registry` — read-only, in-memory
- `LoadedPack` has: `id, version, display_name, profile_schema, product_schema, taxonomy, compatibility_rules (list), prompts (dict), copy (dict[str, dict])`
- Auth: `get_tenant` dep (X-eShopeo-Tenant-Key)
- `copy` keys are locale codes (e.g. `"en"`) — expose only the locale names in the detail response
- `taxonomy` is a dict — expose its keys as available categories in the detail response
- `asyncio_mode = "auto"` — no `@pytest.mark.asyncio`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_packs_endpoint.py
from unittest.mock import MagicMock
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db, get_tenant
from eshopeo.db.models import Tenant
from eshopeo.packs.registry import _registry
from tests.conftest import make_test_settings


@pytest.fixture(autouse=True)
def seed_registry():
    mock_pack = MagicMock()
    mock_pack.id = "kbeauty"
    mock_pack.display_name = "K-Beauty"
    mock_pack.version = "1.0"
    mock_pack.compatibility_rules = [{"ingredients": ["retinol"], "conflicts": ["aha"]}]
    mock_pack.taxonomy = {"cleanser": {}, "toner": {}}
    mock_pack.copy = {"en": {"shipping": "Free shipping"}}

    original = dict(_registry)
    _registry.clear()
    _registry["kbeauty"] = mock_pack
    yield
    _registry.clear()
    _registry.update(original)


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
    from unittest.mock import AsyncMock
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)
    app.dependency_overrides[get_db] = lambda: mock_db
    app.dependency_overrides[get_tenant] = lambda: tenant
    return TestClient(app), tenant


def test_list_packs_returns_loaded_packs(client):
    c, tenant = client
    r = c.get("/v1/packs",
              headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)})
    assert r.status_code == 200
    data = r.json()
    assert isinstance(data, list)
    assert len(data) == 1
    assert data[0]["id"] == "kbeauty"
    assert data[0]["display_name"] == "K-Beauty"


def test_get_pack_returns_detail(client):
    c, tenant = client
    r = c.get("/v1/packs/kbeauty",
              headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)})
    assert r.status_code == 200
    data = r.json()
    assert data["id"] == "kbeauty"
    assert data["compatibility_rules_count"] == 1
    assert "en" in data["copy_locales"]


def test_get_pack_404_unknown(client):
    c, tenant = client
    r = c.get("/v1/packs/unknown-pack",
              headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)})
    assert r.status_code == 404


def test_list_packs_requires_auth(client):
    c, _ = client
    r = c.get("/v1/packs")
    assert r.status_code == 401
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_packs_endpoint.py -v
```
Expected: 4 FAIL (routes don't exist)

- [ ] **Step 3: Create `eshopeo/api/routers/packs.py`**

```python
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel

from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Tenant
from eshopeo.packs.registry import _registry

router = APIRouter(prefix="/v1/packs", tags=["packs"])


class PackSummary(BaseModel):
    id: str
    display_name: str
    version: str


class PackDetail(BaseModel):
    id: str
    display_name: str
    version: str
    compatibility_rules_count: int
    taxonomy_categories: list[str]
    copy_locales: list[str]


@router.get("", response_model=list[PackSummary])
async def list_packs(
    _tenant: Tenant = Depends(get_tenant),
) -> list[PackSummary]:
    return [
        PackSummary(id=p.id, display_name=p.display_name, version=p.version)
        for p in _registry.values()
    ]


@router.get("/{pack_id}", response_model=PackDetail)
async def get_pack_detail(
    pack_id: str,
    _tenant: Tenant = Depends(get_tenant),
) -> PackDetail:
    if pack_id not in _registry:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Pack not found")
    p = _registry[pack_id]
    return PackDetail(
        id=p.id,
        display_name=p.display_name,
        version=p.version,
        compatibility_rules_count=len(p.compatibility_rules),
        taxonomy_categories=list(p.taxonomy.keys()) if isinstance(p.taxonomy, dict) else [],
        copy_locales=list(p.copy.keys()),
    )
```

- [ ] **Step 4: Register packs router in `app.py`**

Add after the admin router block:
```python
from eshopeo.api.routers import packs
app.include_router(packs.router)
```

- [ ] **Step 5: Run tests**

```
cd services/core && python -m pytest tests/test_packs_endpoint.py -v
```
Expected: 4 PASS

- [ ] **Step 6: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 130 PASS

- [ ] **Step 7: Commit**

```
git add eshopeo/api/routers/packs.py eshopeo/api/app.py tests/test_packs_endpoint.py
git commit -m "feat: pack listing API GET /v1/packs and GET /v1/packs/{id}"
```

---

### Task 4 (P5-4): Search category filter

**Files:**
- Modify: `services/core/eshopeo/db/crud/products.py` — add `category` param to `vector_search_products`
- Modify: `services/core/eshopeo/api/routers/search.py` — add `category` query param
- Test: `services/core/tests/test_search_category.py`

**Context:**
- `Product.categories` is a `JSONB` column containing a list of strings, e.g. `["toner", "hydrating"]`
- SQLAlchemy JSONB containment: `Product.categories.contains(["toner"])` generates `categories @> '["toner"]'::jsonb`
- Existing `vector_search_products` signature: `(session, tenant_id, query_vector, limit=10, in_stock_only=False)`
- Add `category: str | None = None` as last parameter; default `None` means no category filter
- Update the `filters` list: `if category: filters.append(Product.categories.contains([category]))`
- `asyncio_mode = "auto"` — no `@pytest.mark.asyncio`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_search_category.py
from unittest.mock import AsyncMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db, get_tenant
from eshopeo.db.models import Product, Tenant
from tests.conftest import make_test_settings


@pytest.fixture
def tenant():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc")
    t.id = uuid4()
    t.public_key = uuid4()
    return t


@pytest.fixture
def product(tenant):
    p = Product(
        tenant_id=tenant.id, platform_id="p1", title="Toner",
        price_minor=5000, currency="ZAR", in_stock=True,
        categories=["toner", "hydrating"], domain_attributes={},
    )
    p.id = uuid4()
    return p


@pytest.fixture
def client(tenant, product):
    settings = make_test_settings()
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)
    app.dependency_overrides[get_db] = lambda: mock_db
    app.dependency_overrides[get_tenant] = lambda: tenant

    with (
        patch("eshopeo.api.routers.search.embed_query", AsyncMock(return_value=[0.1] * 1024)),
        patch("eshopeo.api.routers.search.vector_search_products",
              AsyncMock(return_value=[(product, 0.95)])),
    ):
        yield TestClient(app), tenant, product


def test_search_with_category_passes_param(client):
    c, tenant, _ = client
    r = c.get(
        "/v1/search/products?q=toner&category=toner",
        headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
    )
    assert r.status_code == 200
    data = r.json()
    assert data["total"] == 1


def test_search_without_category_still_works(client):
    c, tenant, _ = client
    r = c.get(
        "/v1/search/products?q=skincare",
        headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
    )
    assert r.status_code == 200
    assert r.json()["total"] == 1


def test_vector_search_passes_category_to_filters():
    from eshopeo.db.crud.products import vector_search_products
    import inspect
    sig = inspect.signature(vector_search_products)
    assert "category" in sig.parameters
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_search_category.py -v
```
Expected: `test_vector_search_passes_category_to_filters` FAIL (param doesn't exist)

- [ ] **Step 3: Add `category` param to `vector_search_products`**

In `eshopeo/db/crud/products.py`, update the signature and add filter:
```python
async def vector_search_products(
    session: AsyncSession,
    tenant_id: UUID,
    query_vector: list[float],
    limit: int = 10,
    in_stock_only: bool = False,
    category: str | None = None,
) -> list[tuple[Product, float]]:
    distance_col = Product.embedding.cosine_distance(query_vector).label("distance")
    filters = [Product.tenant_id == tenant_id, Product.embedding.is_not(None)]
    if in_stock_only:
        filters.append(Product.in_stock.is_(True))
    if category:
        filters.append(Product.categories.contains([category]))
    q = (
        select(Product, distance_col)
        .where(*filters)
        .order_by(distance_col)
        .limit(limit)
    )
    result = await session.execute(q)
    return [(row.Product, 1.0 - row.distance) for row in result]
```

- [ ] **Step 4: Update search router**

In `eshopeo/api/routers/search.py`, add `category` query param and pass to `vector_search_products`:
```python
@router.get("/products", response_model=SearchResponse)
async def search_products(
    q: Annotated[str, Query(min_length=1)],
    limit: Annotated[int, Query(ge=1, le=50)] = 10,
    in_stock_only: bool = False,
    category: str | None = Query(default=None),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> SearchResponse:
    settings = get_settings()
    query_vector = await embed_query(q, settings)
    rows = await vector_search_products(
        db, tenant.id, query_vector, limit, in_stock_only, category
    )
    ...
```

- [ ] **Step 5: Run tests**

```
cd services/core && python -m pytest tests/test_search_category.py -v
```
Expected: 3 PASS

- [ ] **Step 6: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 133 PASS

- [ ] **Step 7: Commit**

```
git add eshopeo/db/crud/products.py eshopeo/api/routers/search.py tests/test_search_category.py
git commit -m "feat: category filter on GET /v1/search/products"
```

---

### Task 5 (P5-5): Full test suite + PROGRESS.md

**Files:**
- Update: `docs/PROGRESS.md`

**Context:**
- Target: ~133 tests passing (119 baseline + 14 new in Phase 5)
- 4 tasks in Phase 5 (no separate P5-5 cleanup task this phase)

- [ ] **Step 1: Run full test suite**

```
cd services/core && python -m pytest -v --tb=short
```
All tests must pass. Fix any failures before updating PROGRESS.md.

- [ ] **Step 2: Update PROGRESS.md**

Update status snapshot, add Phase 5 tasks section (listing 5 tasks as checkboxes), add session log entry before Phase 4 entry.

Session log content:
```
### 2026-06-11 (Phase 5) — Claude Sonnet 4.6
Built Phase 5 Shopify/admin/pack API: Shopify orders webhook (`POST /v1/webhooks/shopify/orders`) with `translate_shopify_order()` and customer_id resolution; admin platform stats (`GET /v1/admin/stats`, auth: provision key, cross-tenant COUNTs + usage SUM); pack listing API (`GET /v1/packs`, `GET /v1/packs/{id}`, reads in-memory registry); search category filter (JSONB `@>` containment on Product.categories). <N> tests total (<M> new Phase 5 + 119 prior). Next: Phase 6 — Shopify PHP orders webhook, onboarding wizard, widget customization.
```

- [ ] **Step 3: Commit**

```
git add docs/PROGRESS.md
git commit -m "docs: Phase 5 complete — <N> tests pass"
```
