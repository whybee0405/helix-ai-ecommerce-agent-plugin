# Phase 16 — Merchant Management APIs Design Spec

**Date:** 2026-06-12
**Status:** Approved
**Scope:** Three merchant-facing gaps that complete the management layer: a content draft review queue (see and filter all AI-generated drafts), a product management router (GET detail + PATCH update without a full re-sync), and a single dashboard summary endpoint that aggregates key merchant metrics in one call.
**Definition of done:** A merchant can list all pending drafts, fetch a product's full detail including description_html, patch product fields, and get an at-a-glance dashboard of store health — all via authenticated merchant API calls.

---

## 1. Gap analysis from Phase 15

| Gap | Impact |
|-----|--------|
| No way to list pending AI drafts in bulk | Merchants must poll each product individually to find un-reviewed drafts |
| No product management endpoint | Merchants can't update a product title/price/description without a full platform re-sync |
| No single dashboard summary | Merchants must hit 6+ analytics endpoints to see store health |

**Already done:** `content.py` CRUD has `get_content_draft`, `approve_content_draft`. `products.py` CRUD has `get_product_by_id`, `browse_products`. Admin CRUD has `get_tenant_usage_summary`. Auth via `get_tenant` is standard.

---

## 2. Content draft list (P16-1)

### New CRUD in `helix/db/crud/content.py`

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

### New endpoint in `helix/api/routers/content.py`

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

**Route must be registered BEFORE `/products/{product_id}/...` routes** in the content router to avoid FastAPI treating `drafts` as a product_id path param.

---

## 3. Product management router (P16-2)

New router `helix/api/routers/products.py`, prefix `/v1/products`, registered in `app.py`.

### New CRUD in `helix/db/crud/products.py`

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

`updates` is a pre-filtered dict — only explicitly set fields (use `model.model_dump(exclude_unset=True)` in the router).

### Router

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
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail="No fields to update")
    product = await update_product(db, tenant.id, product_id, updates)
    if product is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Product not found")
    await db.commit()
    return _product_detail_out(product)
```

**Note:** `description_html: str | None = None` in `ProductUpdate` means a client sending `{"description_html": null}` would explicitly set it to null. Using `exclude_unset=True` (not `exclude_none=True`) preserves this semantic correctly while ignoring fields the client didn't send at all.

---

## 4. Merchant dashboard (P16-3)

New file `helix/db/crud/dashboard.py`. New router `helix/api/routers/dashboard.py`, prefix `/v1/dashboard`, registered in `app.py`.

### Dashboard CRUD

```python
from datetime import datetime, timezone
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

### Dashboard router

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

---

## 5. File map

**New files:**
- `services/core/helix/api/routers/products.py` — `GET /v1/products/{id}`, `PATCH /v1/products/{id}`
- `services/core/helix/api/routers/dashboard.py` — `GET /v1/dashboard`
- `services/core/helix/db/crud/dashboard.py` — `get_dashboard_summary`
- `services/core/tests/test_content_draft_list.py` — 3 tests
- `services/core/tests/test_product_management.py` — 3 tests
- `services/core/tests/test_dashboard.py` — 3 tests

**Modified files:**
- `services/core/helix/db/crud/content.py` — add `list_content_drafts`, `count_content_drafts`
- `services/core/helix/db/crud/products.py` — add `update_product`
- `services/core/helix/api/routers/content.py` — add `GET /v1/content/drafts` (register before `/products/...`)
- `services/core/helix/api/app.py` — include `products.router`, `dashboard.router`

---

## 6. Security constraints

- All queries scoped by `tenant_id` — no cross-tenant access
- `PATCH /v1/products/{id}` uses `exclude_unset=True` — only explicitly-provided fields are updated; missing fields keep their current values
- `description_html` is merchant-controlled HTML; sanitize on render in widget/dashboard, not at API layer
- `credentials_enc` is on the Tenant model, not Product — not in scope

---

## 7. Task breakdown

| Task | Description | Tests |
|------|-------------|-------|
| P16-1 | `list_content_drafts` + `count_content_drafts` CRUD; `GET /v1/content/drafts` endpoint (registered before `/products/...`) | 3 |
| P16-2 | `update_product` CRUD; new `products.py` router with `GET /v1/products/{id}` and `PATCH /v1/products/{id}`; register in `app.py` | 3 |
| P16-3 | `get_dashboard_summary` CRUD in `dashboard.py`; new `dashboard.py` router with `GET /v1/dashboard`; register in `app.py` | 3 |
| P16-4 | Full suite (expected 253 tests, 236 passing) + PROGRESS.md | — |
