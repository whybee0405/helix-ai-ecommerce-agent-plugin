# Phase 10 — Product Similarity & Performance Analytics Design Spec

**Date:** 2026-06-12  
**Status:** Approved  
**Scope:** Product similarity search via pgvector; product performance analytics from conversation references; embedding coverage health check.  
**Definition of done:** Merchants can find products similar to a given product; merchants can see which products the AI recommends most; merchants can check how many products are indexed with embeddings.

---

## 1. Gap analysis from Phase 9

| Gap | Impact |
|-----|--------|
| No way to find products similar to a given product | Merchants cannot build "you might also like" flows; recommendation engine lacks input diversity |
| No visibility into which products the AI references most | Merchants can't see which products drive engagement or identify under-recommended products |
| No visibility into embedding coverage | Merchants don't know if products are searchable; new/updated products may silently lack embeddings |

---

## 2. Product similarity search (P10-1)

### New CRUD: `get_similar_products` in `products.py`

```python
async def get_similar_products(
    session: AsyncSession,
    tenant_id: UUID,
    product_id: UUID,
    limit: int = 5,
) -> list[tuple[Product, float]]:
    # 1. Fetch source product (tenant-scoped)
    # 2. If not found or embedding is None → return []
    # 3. Run cosine distance search excluding source product
    # Returns same format as vector_search_products: list[tuple[Product, float]]
```

Implementation:
```python
source = await session.scalar(
    select(Product).where(
        Product.id == product_id,
        Product.tenant_id == tenant_id,
    )
)
if source is None or source.embedding is None:
    return []

distance_col = Product.embedding.cosine_distance(source.embedding).label("distance")
result = await session.execute(
    select(Product, distance_col)
    .where(
        Product.tenant_id == tenant_id,
        Product.embedding.is_not(None),
        Product.id != product_id,
    )
    .order_by(distance_col)
    .limit(limit)
)
return [(row.Product, 1.0 - row.distance) for row in result]
```

### New endpoint in `search.py`

```
GET /v1/search/similar/{product_id}
```

Auth: `get_tenant`  
Path param: `product_id: UUID`  
Query params: `limit: int = 5` (ge=1, le=20)

Response: `SearchResponse` (same model as existing `GET /v1/search/products`)

Returns 404 if product not found or has no embedding.

### Tests — `test_similar_products.py` (3 tests)

1. `test_similar_products_returns_200` — mock `get_similar_products` returning 2 products; assert 200 + results list length
2. `test_similar_products_404_when_no_embedding` — mock returns empty list; assert 404
3. `test_similar_products_requires_auth` — no `X-eShopeo-Tenant-Key` → 401

---

## 3. Top referenced products (P10-2)

### New CRUD: `get_top_referenced_products` in `conversations.py`

Products mentioned in assistant responses are stored in `ConversationMessage.products_referenced` (JSONB array of product ID strings). Unnest and group to find most-referenced product IDs.

```python
async def get_top_referenced_products(
    session: AsyncSession,
    tenant_id: UUID,
    limit: int = 10,
    start: date | None = None,
    end: date | None = None,
) -> list[dict]:
    # Unnest products_referenced JSONB array
    # Filter: role == "assistant", tenant_id scoped, optional date range
    # Group by product_id string, order by count DESC
    # Returns [{"product_id": str, "count": int}, ...]
```

Implementation using SQLAlchemy `column_valued`:
```python
from sqlalchemy import func

pid_col = func.jsonb_array_elements_text(
    ConversationMessage.products_referenced
).column_valued("pid")

stmt = (
    select(pid_col, func.count().label("cnt"))
    .where(
        ConversationMessage.tenant_id == tenant_id,
        ConversationMessage.role == "assistant",
    )
)
if start:
    start_dt = datetime(start.year, start.month, start.day, tzinfo=timezone.utc)
    stmt = stmt.where(ConversationMessage.created_at >= start_dt)
if end:
    end_dt = datetime(end.year, end.month, end.day, tzinfo=timezone.utc) + timedelta(days=1)
    stmt = stmt.where(ConversationMessage.created_at < end_dt)

stmt = stmt.group_by(pid_col).order_by(func.count().desc()).limit(limit)
result = await session.execute(stmt)
return [{"product_id": row.pid, "count": row.cnt} for row in result.all()]
```

### New endpoint in `analytics.py`

```
GET /v1/analytics/products/top
```

Auth: `get_tenant`  
Query params: `limit: int = 10` (ge=1, le=50), optional `start_date`, `end_date`

Response:
```json
{
  "products": [
    {"product_id": "abc-123", "count": 12},
    {"product_id": "def-456", "count": 8}
  ]
}
```

### Tests — `test_top_referenced_products.py` (3 tests)

1. `test_top_referenced_products_returns_200` — mock `get_top_referenced_products` returning 2 items; assert 200 + list
2. `test_top_referenced_products_requires_auth` — 401
3. `test_top_referenced_products_empty_list` — mock returns `[]`; assert `products: []`

---

## 4. Embedding coverage (P10-3)

### New CRUD: `get_embedding_coverage` in `products.py`

```python
async def get_embedding_coverage(
    session: AsyncSession,
    tenant_id: UUID,
) -> dict:
    # COUNT(*) and COUNT(embedding) — COUNT ignores NULL values
    # Returns total, embedded, missing, coverage_rate
```

Implementation:
```python
result = await session.execute(
    select(
        func.count(Product.id).label("total"),
        func.count(Product.embedding).label("embedded"),
    ).where(Product.tenant_id == tenant_id)
)
row = result.one()
total = row.total or 0
embedded = row.embedded or 0
missing = total - embedded
coverage_rate = round(embedded / total, 2) if total > 0 else 1.0
return {"total": total, "embedded": embedded, "missing": missing, "coverage_rate": coverage_rate}
```

### New endpoint in `analytics.py`

```
GET /v1/analytics/products/embedding-coverage
```

Auth: `get_tenant`

Response:
```json
{
  "total": 150,
  "embedded": 148,
  "missing": 2,
  "coverage_rate": 0.99
}
```

### Tests — `test_embedding_coverage.py` (3 tests)

1. `test_embedding_coverage_returns_200` — mock returns full coverage; assert 200 + all fields
2. `test_embedding_coverage_requires_auth` — 401
3. `test_embedding_coverage_zero_products` — mock returns `{total: 0, embedded: 0, missing: 0, coverage_rate: 1.0}`

---

## 5. File map

**Modified files:**
- `services/core/eshopeo/db/crud/products.py` — add `get_similar_products`, `get_embedding_coverage`
- `services/core/eshopeo/db/crud/conversations.py` — add `get_top_referenced_products`
- `services/core/eshopeo/api/routers/search.py` — add `GET /v1/search/similar/{product_id}`
- `services/core/eshopeo/api/routers/analytics.py` — add `GET /v1/analytics/products/top`, `GET /v1/analytics/products/embedding-coverage`

**New files:**
- `services/core/tests/test_similar_products.py` (3 tests)
- `services/core/tests/test_top_referenced_products.py` (3 tests)
- `services/core/tests/test_embedding_coverage.py` (3 tests)

---

## 6. Security constraints

- All queries scoped by `tenant_id` — no cross-tenant product visibility
- `product_id` in similarity URL validated as UUID — invalid → 422 (FastAPI handles)
- Embedding vectors from DB (trusted) — not from user input
