# Phase 13 — Search Enhancements & Bulk Re-embedding Design Spec

**Date:** 2026-06-12
**Status:** Approved
**Scope:** Add price range filters to semantic search; add a non-semantic browse endpoint for filter-only product discovery; add a bulk re-embedding trigger so merchants can queue all un-embedded products in one call.
**Definition of done:** Merchants can filter semantic search results by price; browse their full catalog without a query string using filters; trigger re-embedding of products that have no embedding vector.

---

## 1. Gap analysis from Phase 12

| Gap | Impact |
|-----|--------|
| Semantic search ignores price | Merchants can't surface results within a budget range |
| No filter-only browse endpoint | Browsing the catalog requires either a query or a sync export |
| No way to trigger bulk re-embedding | Products synced before the embedding pipeline was set up have no vectors; fixing them requires per-product manual intervention |

**Already implemented (do not re-implement):** `in_stock_only` and single-category filter on semantic search are in `vector_search_products` and `GET /v1/search/products`.

---

## 2. Price range filters on semantic search (P13-1)

### Modify `vector_search_products` in `products.py`

Add two optional keyword params:
```python
async def vector_search_products(
    session: AsyncSession,
    tenant_id: UUID,
    query_vector: list[float],
    limit: int = 10,
    in_stock_only: bool = False,
    category: str | None = None,
    min_price: int | None = None,
    max_price: int | None = None,
) -> list[tuple[Product, float]]:
```

Add to filter construction (after existing `category` filter):
```python
if min_price is not None:
    filters.append(Product.price_minor >= min_price)
if max_price is not None:
    filters.append(Product.price_minor <= max_price)
```

### Modify `GET /v1/search/products` in `search.py`

Add query params (after `category`):
```python
min_price: int | None = Query(default=None, ge=0),
max_price: int | None = Query(default=None, ge=0),
```

Pass to `vector_search_products`:
```python
rows = await vector_search_products(
    db, tenant.id, query_vector, limit, in_stock_only,
    category=category, min_price=min_price, max_price=max_price,
)
```

### Tests — `test_search_price_filter.py` (3 tests)

1. `test_search_with_price_filter_returns_200` — mock `vector_search_products` and `embed_query`; pass `min_price=1000&max_price=5000`; assert 200 + results
2. `test_search_price_filter_passed_to_crud` — mock `vector_search_products` capturing kwargs; assert `min_price=1000` and `max_price=5000` in call
3. `test_search_requires_auth` — 401

---

## 3. Product browse endpoint (P13-2)

### New CRUD: `browse_products` in `products.py`

```python
async def browse_products(
    session: AsyncSession,
    tenant_id: UUID,
    in_stock_only: bool = False,
    category: str | None = None,
    min_price: int | None = None,
    max_price: int | None = None,
    limit: int = 20,
    offset: int = 0,
) -> tuple[list[Product], int]:
    filters = [Product.tenant_id == tenant_id]
    if in_stock_only:
        filters.append(Product.in_stock.is_(True))
    if category:
        filters.append(Product.categories.contains([category]))
    if min_price is not None:
        filters.append(Product.price_minor >= min_price)
    if max_price is not None:
        filters.append(Product.price_minor <= max_price)

    count_result = await session.execute(
        select(func.count(Product.id)).where(*filters)
    )
    total = count_result.scalar_one()

    result = await session.execute(
        select(Product)
        .where(*filters)
        .order_by(Product.price_minor.asc())
        .limit(limit)
        .offset(offset)
    )
    return list(result.scalars().all()), total
```

### New endpoint in `search.py`

**`GET /v1/search/browse`** — no `q` param required

New models:
```python
class ProductOut(BaseModel):
    id: str
    platform_id: str
    title: str
    price_minor: int
    currency: str
    in_stock: bool
    categories: list[str]
    domain_attributes: dict

class BrowseResponse(BaseModel):
    products: list[ProductOut]
    total: int
    limit: int
    offset: int
```

Endpoint:
```python
@router.get("/browse", response_model=BrowseResponse)
async def browse_products_endpoint(
    in_stock_only: bool = False,
    category: str | None = Query(default=None),
    min_price: int | None = Query(default=None, ge=0),
    max_price: int | None = Query(default=None, ge=0),
    limit: Annotated[int, Query(ge=1, le=100)] = 20,
    offset: Annotated[int, Query(ge=0)] = 0,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> BrowseResponse:
    products, total = await browse_products(
        db, tenant.id,
        in_stock_only=in_stock_only,
        category=category,
        min_price=min_price,
        max_price=max_price,
        limit=limit,
        offset=offset,
    )
    return BrowseResponse(
        products=[
            ProductOut(
                id=str(p.id),
                platform_id=p.platform_id,
                title=p.title,
                price_minor=p.price_minor,
                currency=p.currency,
                in_stock=p.in_stock,
                categories=p.categories or [],
                domain_attributes=p.domain_attributes or {},
            )
            for p in products
        ],
        total=total,
        limit=limit,
        offset=offset,
    )
```

Add `browse_products` to import from `eshopeo.db.crud.products`.

### Tests — `test_search_browse.py` (3 tests)

1. `test_browse_returns_200` — mock `browse_products` returning `([2 products], 2)`; assert 200 + `products` length + `total`
2. `test_browse_empty_catalog` — mock returns `([], 0)`; assert 200 + `products == []` + `total == 0`
3. `test_browse_requires_auth` — 401

---

## 4. Bulk re-embedding trigger (P13-3)

### New CRUD: `list_products_without_embedding` in `products.py`

```python
async def list_products_without_embedding(
    session: AsyncSession,
    tenant_id: UUID,
) -> list[Product]:
    result = await session.execute(
        select(Product).where(
            Product.tenant_id == tenant_id,
            Product.embedding.is_(None),
        )
    )
    return list(result.scalars().all())
```

### New endpoint in `jobs.py`

```python
from eshopeo.db.crud.products import list_products_without_embedding
from eshopeo.workers.tasks.embedding import embed_product


class BulkEmbedResponse(BaseModel):
    queued: int


@router.post("/embed/bulk", response_model=BulkEmbedResponse)
async def bulk_embed_endpoint(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> BulkEmbedResponse:
    products = await list_products_without_embedding(db, tenant.id)
    for product in products:
        embed_product.delay(str(tenant.id), str(product.id))
    return BulkEmbedResponse(queued=len(products))
```

**Route registration note:** `POST /embed/bulk` must be registered BEFORE `GET /{job_id}` to prevent the UUID path param from consuming the `embed` segment. Since `POST` vs `GET` differ in method, there is no ambiguity — both can coexist.

### Tests — `test_bulk_embed.py` (3 tests)

1. `test_bulk_embed_queues_products` — mock `list_products_without_embedding` returning 3 products; mock `embed_product.delay`; assert 200 + `queued == 3` + `delay` called 3 times
2. `test_bulk_embed_no_products` — mock returns `[]`; assert 200 + `queued == 0`
3. `test_bulk_embed_requires_auth` — 401

Patch namespaces:
- `eshopeo.api.routers.jobs.list_products_without_embedding`
- `eshopeo.api.routers.jobs.embed_product`

---

## 5. File map

**Modified files:**
- `services/core/eshopeo/db/crud/products.py` — modify `vector_search_products` (add price filters), add `browse_products`, add `list_products_without_embedding`
- `services/core/eshopeo/api/routers/search.py` — add price params to `GET /v1/search/products`, add `GET /v1/search/browse` endpoint
- `services/core/eshopeo/api/routers/jobs.py` — add `POST /v1/jobs/embed/bulk`

**New files:**
- `services/core/tests/test_search_price_filter.py` (3 tests)
- `services/core/tests/test_search_browse.py` (3 tests)
- `services/core/tests/test_bulk_embed.py` (3 tests)

---

## 6. Security constraints

- All CRUD queries scoped by `tenant_id`
- Price range params validated `ge=0` — no negative prices
- `embed_product.delay` fires and forgets — no tenant data in Celery message payload beyond IDs
