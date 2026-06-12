# Phase 16 — Merchant Management APIs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a content draft review queue, a product management router, and a merchant dashboard summary endpoint to complete the merchant management layer.

**Architecture:** Three independent additions — new CRUD functions in existing files, two new routers (`products.py`, `dashboard.py`), a new CRUD file (`dashboard.py`), and two new endpoints on the existing content router.

**Tech Stack:** FastAPI, SQLAlchemy async, Pydantic v2, pytest with `asyncio_mode = "auto"`.

---

## Critical context for all tasks

- **`asyncio_mode = "auto"`** — NEVER add `@pytest.mark.asyncio` to any test.
- **Test pattern**: `app.dependency_overrides[dep] = lambda: mock` + `.clear()` after each test.
- **Mock namespace**: patch at where the name is USED (`helix.api.routers.content.list_content_drafts`, not `helix.db.crud.content.list_content_drafts`).
- **CRUD pattern**: `list(result.scalars().all())` for list queries.
- **Auth**: `get_tenant` for all merchant endpoints. No admin endpoints in this phase.
- **Route ordering**: more specific literal routes before path-param catch-alls.
- **`exclude_unset=True`** on Pydantic model dumps for PATCH endpoints.
- Working directory: `D:\Dev Projects\ai-ecommerce-master-plugin-beauty\services\core`
- Shell: PowerShell on Windows.
- Run tests: `python -m pytest <test_file> -v`

---

## Task P16-1: Content draft list endpoint

**Files:**
- Modify: `helix/db/crud/content.py`
- Modify: `helix/api/routers/content.py`
- Create: `tests/test_content_draft_list.py`

### Step 1: Add CRUD to `helix/db/crud/content.py`

Append after `list_products_without_draft`:

```python
async def list_content_drafts(
    session: AsyncSession,
    tenant_id: UUID,
    status: str | None = None,
    limit: int = 20,
    offset: int = 0,
) -> list[ContentDraft]:
    filters = [ContentDraft.tenant_id == tenant_id]
    if status is not None:
        filters.append(ContentDraft.status == status)
    result = await session.execute(
        select(ContentDraft)
        .where(*filters)
        .order_by(ContentDraft.created_at.desc())
        .limit(limit)
        .offset(offset)
    )
    return list(result.scalars().all())


async def count_content_drafts(
    session: AsyncSession,
    tenant_id: UUID,
    status: str | None = None,
) -> int:
    filters = [ContentDraft.tenant_id == tenant_id]
    if status is not None:
        filters.append(ContentDraft.status == status)
    result = await session.execute(
        select(func.count(ContentDraft.id)).where(*filters)
    )
    return result.scalar_one()
```

Add `func` to the existing `from sqlalchemy import delete, select` import: `from sqlalchemy import delete, func, select`.

### Step 2: Add endpoint to `helix/api/routers/content.py`

Add import at top:
```python
from typing import Annotated
from helix.db.crud.content import (
    approve_content_draft,
    count_content_drafts,
    get_content_draft,
    list_content_drafts,
    list_products_without_draft,
)
```
(add `count_content_drafts`, `list_content_drafts` to existing import; add `Annotated` to `from typing import`; add `Query` to `from fastapi import`)

Add the response model and endpoint **before** the existing `@router.post("/products/{product_id}/generate", ...)` route:

```python
class ContentDraftListResponse(BaseModel):
    items: list[ContentDraftOut]
    total: int
    limit: int
    offset: int


@router.get("/drafts", response_model=ContentDraftListResponse)
async def list_drafts(
    status: str | None = Query(default=None),
    limit: Annotated[int, Query(ge=1, le=100)] = 20,
    offset: Annotated[int, Query(ge=0)] = 0,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ContentDraftListResponse:
    drafts = await list_content_drafts(db, tenant.id, status=status, limit=limit, offset=offset)
    total = await count_content_drafts(db, tenant.id, status=status)
    return ContentDraftListResponse(
        items=[_draft_out(d) for d in drafts],
        total=total,
        limit=limit,
        offset=offset,
    )
```

### Step 3: Create `tests/test_content_draft_list.py`

```python
from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import ContentDraft, Tenant
from tests.conftest import make_test_settings


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    return t


def _make_draft(tenant_id, status="pending"):
    d = MagicMock(spec=ContentDraft)
    d.product_id = uuid4()
    d.tenant_id = tenant_id
    d.field = "description_html"
    d.draft_text = "<p>Draft</p>"
    d.status = status
    d.created_at = datetime(2026, 6, 12, tzinfo=timezone.utc)
    d.approved_at = None
    return d


def test_list_drafts_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    drafts = [_make_draft(tenant.id), _make_draft(tenant.id)]
    app.dependency_overrides[get_tenant] = lambda: tenant

    with (
        patch("helix.api.routers.content.list_content_drafts", new_callable=AsyncMock, return_value=drafts),
        patch("helix.api.routers.content.count_content_drafts", new_callable=AsyncMock, return_value=2),
    ):
        r = client.get("/v1/content/drafts")

    app.dependency_overrides.clear()
    assert r.status_code == 200
    body = r.json()
    assert body["total"] == 2
    assert len(body["items"]) == 2


def test_list_drafts_passes_status_filter():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    mock_list = AsyncMock(return_value=[_make_draft(tenant.id, status="pending")])
    mock_count = AsyncMock(return_value=1)
    app.dependency_overrides[get_tenant] = lambda: tenant

    with (
        patch("helix.api.routers.content.list_content_drafts", mock_list),
        patch("helix.api.routers.content.count_content_drafts", mock_count),
    ):
        r = client.get("/v1/content/drafts?status=pending")

    app.dependency_overrides.clear()
    assert r.status_code == 200
    _, kwargs = mock_list.call_args
    assert kwargs.get("status") == "pending" or mock_list.call_args[0][2] == "pending"


def test_list_drafts_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/content/drafts")
    assert r.status_code == 401
```

### Step 4: Syntax check and run tests

```powershell
python -m py_compile helix/db/crud/content.py helix/api/routers/content.py tests/test_content_draft_list.py
python -m pytest tests/test_content_draft_list.py -v
```

All 3 must pass.

### Step 5: Commit

```powershell
git add helix/db/crud/content.py helix/api/routers/content.py tests/test_content_draft_list.py
git commit -m "feat: content draft list endpoint — GET /v1/content/drafts with status filter"
```

---

## Task P16-2: Product management router

**Files:**
- Modify: `helix/db/crud/products.py`
- Create: `helix/api/routers/products.py`
- Modify: `helix/api/app.py`
- Create: `tests/test_product_management.py`

### Step 1: Add `update_product` to `helix/db/crud/products.py`

Append after `get_product_by_id`:

```python
async def update_product(
    session: AsyncSession,
    tenant_id: UUID,
    product_id: UUID,
    updates: dict,
) -> Product | None:
    product = await get_product_by_id(session, tenant_id, product_id)
    if product is None:
        return None
    for field, value in updates.items():
        setattr(product, field, value)
    session.add(product)
    await session.flush()
    await session.refresh(product)
    return product
```

### Step 2: Create `helix/api/routers/products.py`

```python
from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.db.crud.products import get_product_by_id, update_product
from helix.db.models import Tenant

router = APIRouter(prefix="/v1/products", tags=["products"])


class ProductDetailOut(BaseModel):
    id: str
    platform_id: str
    title: str
    description_html: str | None
    price_minor: int
    currency: str
    in_stock: bool
    categories: list[str]
    domain_attributes: dict


class ProductUpdate(BaseModel):
    title: str | None = None
    description_html: str | None = None
    price_minor: int | None = None
    categories: list[str] | None = None
    in_stock: bool | None = None


def _product_detail_out(p) -> ProductDetailOut:
    return ProductDetailOut(
        id=str(p.id),
        platform_id=p.platform_id,
        title=p.title,
        description_html=p.description_html,
        price_minor=p.price_minor,
        currency=p.currency,
        in_stock=p.in_stock,
        categories=p.categories or [],
        domain_attributes=p.domain_attributes or {},
    )


@router.get("/{product_id}", response_model=ProductDetailOut)
async def get_product(
    product_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ProductDetailOut:
    product = await get_product_by_id(db, tenant.id, product_id)
    if product is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Product not found")
    return _product_detail_out(product)


@router.patch("/{product_id}", response_model=ProductDetailOut)
async def patch_product(
    product_id: UUID,
    body: ProductUpdate,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ProductDetailOut:
    updates = body.model_dump(exclude_unset=True)
    if not updates:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail="No fields to update",
        )
    product = await update_product(db, tenant.id, product_id, updates)
    if product is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Product not found")
    await db.commit()
    return _product_detail_out(product)
```

### Step 3: Register in `helix/api/app.py`

Read the file and add after the content router include:

```python
    from helix.api.routers import products
    app.include_router(products.router)
```

### Step 4: Create `tests/test_product_management.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Product, Tenant
from tests.conftest import make_test_settings


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    return t


def _make_product(tenant_id):
    p = MagicMock(spec=Product)
    p.id = uuid4()
    p.tenant_id = tenant_id
    p.platform_id = "prod-123"
    p.title = "COSRX Snail Cream"
    p.description_html = "<p>Original</p>"
    p.price_minor = 2500
    p.currency = "USD"
    p.in_stock = True
    p.categories = ["moisturizer"]
    p.domain_attributes = {"skin_type": "all"}
    return p


def test_get_product_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product = _make_product(tenant.id)
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("helix.api.routers.products.get_product_by_id", new_callable=AsyncMock, return_value=product):
        r = client.get(f"/v1/products/{product.id}")

    app.dependency_overrides.clear()
    assert r.status_code == 200
    assert r.json()["title"] == "COSRX Snail Cream"
    assert "description_html" in r.json()


def test_get_product_404_when_not_found():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("helix.api.routers.products.get_product_by_id", new_callable=AsyncMock, return_value=None):
        r = client.get(f"/v1/products/{uuid4()}")

    app.dependency_overrides.clear()
    assert r.status_code == 404


def test_patch_product_returns_updated_product():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product = _make_product(tenant.id)
    product.title = "Updated Title"
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_db = AsyncMock()
    mock_db.commit = AsyncMock()

    with patch("helix.api.routers.products.update_product", new_callable=AsyncMock, return_value=product):
        r = client.patch(f"/v1/products/{product.id}", json={"title": "Updated Title"})

    app.dependency_overrides.clear()
    assert r.status_code == 200
    assert r.json()["title"] == "Updated Title"
```

### Step 5: Syntax check and run tests

```powershell
python -m py_compile helix/db/crud/products.py helix/api/routers/products.py helix/api/app.py tests/test_product_management.py
python -m pytest tests/test_product_management.py -v
```

All 3 must pass.

### Step 6: Commit

```powershell
git add helix/db/crud/products.py helix/api/routers/products.py helix/api/app.py tests/test_product_management.py
git commit -m "feat: product management router — GET /v1/products/{id} and PATCH /v1/products/{id}"
```

---

## Task P16-3: Merchant dashboard

**Files:**
- Create: `helix/db/crud/dashboard.py`
- Create: `helix/api/routers/dashboard.py`
- Modify: `helix/api/app.py`
- Create: `tests/test_dashboard.py`

### Step 1: Create `helix/db/crud/dashboard.py`

```python
from datetime import datetime
from uuid import UUID

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.crud.admin import get_tenant_usage_summary
from helix.db.models import ContentDraft, Conversation, Customer, Product


async def get_dashboard_summary(
    session: AsyncSession,
    tenant_id: UUID,
    month_start: datetime,
    month_end: datetime,
) -> dict:
    product_count = (
        await session.execute(
            select(func.count(Product.id)).where(Product.tenant_id == tenant_id)
        )
    ).scalar_one()

    customer_count = (
        await session.execute(
            select(func.count(Customer.id)).where(Customer.tenant_id == tenant_id)
        )
    ).scalar_one()

    conversations_this_month = (
        await session.execute(
            select(func.count(Conversation.id)).where(
                Conversation.tenant_id == tenant_id,
                Conversation.created_at >= month_start,
                Conversation.created_at < month_end,
            )
        )
    ).scalar_one()

    pending_drafts = (
        await session.execute(
            select(func.count(ContentDraft.id)).where(
                ContentDraft.tenant_id == tenant_id,
                ContentDraft.status == "pending",
            )
        )
    ).scalar_one()

    usage = await get_tenant_usage_summary(session, tenant_id, month_start, month_end)

    return {
        "product_count": product_count,
        "customer_count": customer_count,
        "conversations_this_month": conversations_this_month,
        "pending_drafts": pending_drafts,
        "queries_this_month": usage["total_queries"],
        "cost_this_month_usd": usage["total_cost_usd"],
    }
```

### Step 2: Create `helix/api/routers/dashboard.py`

```python
from datetime import datetime, timezone

from fastapi import APIRouter, Depends
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.config import get_settings
from helix.db.crud.dashboard import get_dashboard_summary
from helix.db.models import Tenant

router = APIRouter(prefix="/v1/dashboard", tags=["dashboard"])


class DashboardOut(BaseModel):
    product_count: int
    customer_count: int
    conversations_this_month: int
    pending_drafts: int
    queries_this_month: int
    cost_this_month_usd: float
    quota_limit: int
    quota_used: int


@router.get("", response_model=DashboardOut)
async def get_dashboard(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> DashboardOut:
    settings = get_settings()
    now = datetime.now(timezone.utc)
    year, mon = now.year, now.month
    month_start = datetime(year, mon, 1, tzinfo=timezone.utc)
    next_mon, next_year = (mon + 1, year) if mon < 12 else (1, year + 1)
    month_end = datetime(next_year, next_mon, 1, tzinfo=timezone.utc)

    summary = await get_dashboard_summary(db, tenant.id, month_start, month_end)
    return DashboardOut(
        **summary,
        quota_limit=settings.default_monthly_query_limit,
        quota_used=summary["queries_this_month"],
    )
```

### Step 3: Register in `helix/api/app.py`

Add after the products router include:

```python
    from helix.api.routers import dashboard
    app.include_router(dashboard.router)
```

### Step 4: Create `tests/test_dashboard.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Tenant
from tests.conftest import make_test_settings

_SUMMARY = {
    "product_count": 42,
    "customer_count": 10,
    "conversations_this_month": 5,
    "pending_drafts": 3,
    "queries_this_month": 20,
    "cost_this_month_usd": 0.004,
}


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    return t


def test_dashboard_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("helix.api.routers.dashboard.get_dashboard_summary", new_callable=AsyncMock, return_value=_SUMMARY):
        r = client.get("/v1/dashboard")

    app.dependency_overrides.clear()
    assert r.status_code == 200


def test_dashboard_contains_expected_fields():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("helix.api.routers.dashboard.get_dashboard_summary", new_callable=AsyncMock, return_value=_SUMMARY):
        r = client.get("/v1/dashboard")

    app.dependency_overrides.clear()
    body = r.json()
    assert body["product_count"] == 42
    assert body["pending_drafts"] == 3
    assert body["quota_limit"] == settings.default_monthly_query_limit
    assert body["quota_used"] == body["queries_this_month"]


def test_dashboard_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/dashboard")
    assert r.status_code == 401
```

### Step 5: Syntax check and run tests

```powershell
python -m py_compile helix/db/crud/dashboard.py helix/api/routers/dashboard.py helix/api/app.py tests/test_dashboard.py
python -m pytest tests/test_dashboard.py -v
```

All 3 must pass.

### Step 6: Commit

```powershell
git add helix/db/crud/dashboard.py helix/api/routers/dashboard.py helix/api/app.py tests/test_dashboard.py
git commit -m "feat: merchant dashboard — GET /v1/dashboard aggregates product/customer/usage metrics"
```

---

## Task P16-4: Full suite + PROGRESS.md

### Step 1: Run full suite

```powershell
python -m pytest --tb=short -q
```

Expected: 253 total, 236 passing, 17 failing (same infra-only failures as before: widget chat, conversation context, usage event persistence, widget stream). Any new failure is a regression — fix it before updating PROGRESS.md.

### Step 2: Update `docs/PROGRESS.md`

- Change `Current phase` to `Phase 16 — Merchant Management APIs`
- Update test counts: `236/253 tests pass`
- Add Phase 16 section with all 4 tasks marked complete
- Add session log entry

### Step 3: Commit

```powershell
git add docs/PROGRESS.md
git commit -m "docs: update PROGRESS.md for Phase 16 completion"
```
