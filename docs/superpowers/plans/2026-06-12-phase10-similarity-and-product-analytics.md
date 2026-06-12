# Phase 10 — Product Similarity & Performance Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add product similarity search (pgvector cosine distance), top-referenced-products analytics (from conversation history), and embedding coverage health check.

**Architecture:** Three independent endpoint pairs (CRUD + router); no new models, no migration. All queries scoped by `tenant_id`. Analytics endpoints extend `analytics.py`; similarity extends `search.py`.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy 2.x async (pgvector), pytest (asyncio_mode=auto)

---

## Context for all tasks

- `asyncio_mode = "auto"` — NEVER add `@pytest.mark.asyncio`
- Patch at namespace where name is USED
- Tests call `app.dependency_overrides.clear()` after running
- `Product.embedding` is a `Vector(1024)` nullable column; `cosine_distance()` is a pgvector method
- `ConversationMessage.products_referenced` is a JSONB array of product ID strings (UUIDs as strings)

---

## Task P10-1: Product similarity search

**Files:**
- Modify: `services/core/helix/db/crud/products.py`
- Modify: `services/core/helix/api/routers/search.py`
- Create: `services/core/tests/test_similar_products.py`

### Step 1: Add `get_similar_products` to `products.py`

```python
async def get_similar_products(
    session: AsyncSession,
    tenant_id: UUID,
    product_id: UUID,
    limit: int = 5,
) -> list[tuple[Product, float]]:
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

### Step 2: Add endpoint to `search.py`

Add `get_similar_products` to the import from `helix.db.crud.products`.

Add endpoint at the end of `search.py`:

```python
@router.get("/similar/{product_id}", response_model=SearchResponse)
async def get_similar_products_endpoint(
    product_id: UUID,
    limit: Annotated[int, Query(ge=1, le=20)] = 5,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> SearchResponse:
    rows = await get_similar_products(db, tenant.id, product_id, limit)
    if not rows:
        raise HTTPException(status_code=404, detail="Product not found or has no embedding")
    results = [
        ProductResult(
            id=str(p.id),
            platform_id=p.platform_id,
            title=p.title,
            price_minor=p.price_minor,
            currency=p.currency,
            in_stock=p.in_stock,
            categories=p.categories or [],
            domain_attributes=p.domain_attributes or {},
            score=round(score, 4),
        )
        for p, score in rows
    ]
    return SearchResponse(results=results, total=len(results))
```

Add `HTTPException` and `UUID` to the imports at the top of `search.py`:
- `from uuid import UUID` (if not already present)
- `from fastapi import APIRouter, Depends, HTTPException, Query`

### Step 3: Create `test_similar_products.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Product, Tenant
from tests.conftest import make_test_settings


def _make_mock_product():
    p = MagicMock(spec=Product)
    p.id = uuid4()
    p.platform_id = "prod-1"
    p.title = "Hydrating Serum"
    p.price_minor = 2500
    p.currency = "USD"
    p.in_stock = True
    p.categories = ["serum"]
    p.domain_attributes = {}
    return p


def test_similar_products_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    product_id = uuid4()
    mock_rows = [
        (_make_mock_product(), 0.92),
        (_make_mock_product(), 0.87),
    ]

    with patch(
        "helix.api.routers.search.get_similar_products",
        new_callable=AsyncMock,
        return_value=mock_rows,
    ):
        r = client.get(f"/v1/search/similar/{product_id}")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["results"]) == 2
    assert data["total"] == 2


def test_similar_products_404_when_no_embedding():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch(
        "helix.api.routers.search.get_similar_products",
        new_callable=AsyncMock,
        return_value=[],
    ):
        r = client.get(f"/v1/search/similar/{uuid4()}")

    app.dependency_overrides.clear()

    assert r.status_code == 404


def test_similar_products_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get(f"/v1/search/similar/{uuid4()}")

    assert r.status_code == 401
```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core" && python -m py_compile helix/db/crud/products.py helix/api/routers/search.py tests/test_similar_products.py
```

### Step 5: Commit

```bash
git add services/core/helix/db/crud/products.py services/core/helix/api/routers/search.py services/core/tests/test_similar_products.py
git commit -m "feat: similar products search GET /v1/search/similar/{product_id}"
```

---

## Task P10-2: Top referenced products analytics

**Files:**
- Modify: `services/core/helix/db/crud/conversations.py`
- Modify: `services/core/helix/api/routers/analytics.py`
- Create: `services/core/tests/test_top_referenced_products.py`

### Step 1: Add `get_top_referenced_products` to `conversations.py`

Add at the end of the file (after `get_top_queries`):

```python
async def get_top_referenced_products(
    session: AsyncSession,
    tenant_id: UUID,
    limit: int = 10,
    start: date | None = None,
    end: date | None = None,
) -> list[dict]:
    from datetime import datetime, timezone, timedelta
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

Note: `func` may already be imported from an earlier function — check before adding duplicate import.

### Step 2: Add endpoint to `analytics.py`

Update the conversations import line to include `get_top_referenced_products`:
```python
from helix.db.crud.conversations import get_conversation_analytics, get_top_queries, get_top_referenced_products
```

Add models and endpoint at the end of `analytics.py`:

```python
class TopReferencedProductItem(BaseModel):
    product_id: str
    count: int


class TopReferencedProductsResponse(BaseModel):
    products: list[TopReferencedProductItem]


@router.get("/products/top", response_model=TopReferencedProductsResponse)
async def get_top_referenced_products_endpoint(
    limit: int = Query(default=10, ge=1, le=50),
    start_date: date | None = Query(default=None),
    end_date: date | None = Query(default=None),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> TopReferencedProductsResponse:
    products = await get_top_referenced_products(
        db, tenant.id, limit=limit, start=start_date, end=end_date
    )
    return TopReferencedProductsResponse(
        products=[TopReferencedProductItem(**p) for p in products]
    )
```

### Step 3: Create `test_top_referenced_products.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def test_top_referenced_products_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_products = [
        {"product_id": str(uuid4()), "count": 15},
        {"product_id": str(uuid4()), "count": 9},
    ]

    with patch(
        "helix.api.routers.analytics.get_top_referenced_products",
        new_callable=AsyncMock,
        return_value=mock_products,
    ):
        r = client.get("/v1/analytics/products/top")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["products"]) == 2
    assert data["products"][0]["count"] == 15


def test_top_referenced_products_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/products/top")

    assert r.status_code == 401


def test_top_referenced_products_empty_list():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch(
        "helix.api.routers.analytics.get_top_referenced_products",
        new_callable=AsyncMock,
        return_value=[],
    ):
        r = client.get("/v1/analytics/products/top")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["products"] == []
```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core" && python -m py_compile helix/db/crud/conversations.py helix/api/routers/analytics.py tests/test_top_referenced_products.py
```

### Step 5: Commit

```bash
git add services/core/helix/db/crud/conversations.py services/core/helix/api/routers/analytics.py services/core/tests/test_top_referenced_products.py
git commit -m "feat: top referenced products analytics GET /v1/analytics/products/top"
```

---

## Task P10-3: Embedding coverage health check

**Files:**
- Modify: `services/core/helix/db/crud/products.py`
- Modify: `services/core/helix/api/routers/analytics.py`
- Create: `services/core/tests/test_embedding_coverage.py`

### Step 1: Add `get_embedding_coverage` to `products.py`

```python
from sqlalchemy import func

async def get_embedding_coverage(
    session: AsyncSession,
    tenant_id: UUID,
) -> dict:
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
    return {
        "total": total,
        "embedded": embedded,
        "missing": missing,
        "coverage_rate": coverage_rate,
    }
```

Note: `func` and `select` may already be imported — check before adding.

### Step 2: Add endpoint to `analytics.py`

Add import from products CRUD:
```python
from helix.db.crud.products import get_embedding_coverage
```

Add model and endpoint at the end of `analytics.py`:

```python
class EmbeddingCoverage(BaseModel):
    total: int
    embedded: int
    missing: int
    coverage_rate: float


@router.get("/products/embedding-coverage", response_model=EmbeddingCoverage)
async def get_embedding_coverage_endpoint(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> EmbeddingCoverage:
    coverage = await get_embedding_coverage(db, tenant.id)
    return EmbeddingCoverage(**coverage)
```

### Step 3: Create `test_embedding_coverage.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def test_embedding_coverage_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_coverage = {
        "total": 150,
        "embedded": 148,
        "missing": 2,
        "coverage_rate": 0.99,
    }

    with patch(
        "helix.api.routers.analytics.get_embedding_coverage",
        new_callable=AsyncMock,
        return_value=mock_coverage,
    ):
        r = client.get("/v1/analytics/products/embedding-coverage")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["total"] == 150
    assert data["missing"] == 2
    assert data["coverage_rate"] == 0.99


def test_embedding_coverage_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/products/embedding-coverage")

    assert r.status_code == 401


def test_embedding_coverage_zero_products():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_coverage = {
        "total": 0,
        "embedded": 0,
        "missing": 0,
        "coverage_rate": 1.0,
    }

    with patch(
        "helix.api.routers.analytics.get_embedding_coverage",
        new_callable=AsyncMock,
        return_value=mock_coverage,
    ):
        r = client.get("/v1/analytics/products/embedding-coverage")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["coverage_rate"] == 1.0


```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core" && python -m py_compile helix/db/crud/products.py helix/api/routers/analytics.py tests/test_embedding_coverage.py
```

### Step 5: Commit

```bash
git add services/core/helix/db/crud/products.py services/core/helix/api/routers/analytics.py services/core/tests/test_embedding_coverage.py
git commit -m "feat: embedding coverage health check GET /v1/analytics/products/embedding-coverage"
```

---

## Task P10-4: Full suite + PROGRESS.md

Update `docs/PROGRESS.md`:
- Status: Phase 10 complete, 193/193 tests pass (184 prior + 3 + 3 + 3 = 193)
- Add Phase 10 section and session log entry
- Commit: `git add docs/PROGRESS.md && git commit -m "docs: Phase 10 complete — 193 tests"`
