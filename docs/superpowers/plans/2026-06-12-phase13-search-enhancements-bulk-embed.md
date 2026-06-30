# Phase 13 — Search Enhancements & Bulk Re-embedding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add price range filters to semantic search, a no-query browse endpoint, and a bulk re-embedding trigger.

**Architecture:** `vector_search_products` gains `min_price`/`max_price` keyword args; new `browse_products` and `list_products_without_embedding` CRUD functions in `products.py`; `GET /v1/search/browse` and updated `GET /v1/search/products` in `search.py`; `POST /v1/jobs/embed/bulk` in `jobs.py`. No new models, no migration.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy 2.x async, Celery, pytest (asyncio_mode=auto)

---

## Context for all tasks

- `asyncio_mode = "auto"` — NEVER add `@pytest.mark.asyncio`
- Patch at namespace where name is USED
- Tests call `app.dependency_overrides.clear()` after running (tests that set overrides)
- `vector_search_products` already has `in_stock_only` and `category` — add `min_price`/`max_price` as keyword-only args with default `None`
- `Product.embedding.is_(None)` — correct SQLAlchemy null check for vector column
- `Product.categories.contains([category])` — JSONB array containment (already in codebase)
- `embed_product` is a Celery task from `eshopeo.workers.tasks.embedding`; `.delay(tenant_id_str, product_id_str)` queues it

---

## Task P13-1: Price range filters on semantic search

**Files:**
- Modify: `services/core/eshopeo/db/crud/products.py`
- Modify: `services/core/eshopeo/api/routers/search.py`
- Create: `services/core/tests/test_search_price_filter.py`

### Step 1: Modify `vector_search_products` in `products.py`

Read the file first to see the current signature. Add two keyword params after `category`:

**Current signature:**
```python
async def vector_search_products(
    session: AsyncSession,
    tenant_id: UUID,
    query_vector: list[float],
    limit: int = 10,
    in_stock_only: bool = False,
    category: str | None = None,
) -> list[tuple[Product, float]]:
```

**New signature:**
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

Add filter lines inside the function body after the `if category:` block:
```python
if min_price is not None:
    filters.append(Product.price_minor >= min_price)
if max_price is not None:
    filters.append(Product.price_minor <= max_price)
```

### Step 2: Modify `GET /v1/search/products` in `search.py`

Read the file first. Add two query params after `category`:

```python
min_price: int | None = Query(default=None, ge=0),
max_price: int | None = Query(default=None, ge=0),
```

Update the `vector_search_products` call to pass them:
```python
rows = await vector_search_products(
    db, tenant.id, query_vector, limit, in_stock_only,
    category=category, min_price=min_price, max_price=max_price,
)
```

### Step 3: Create `test_search_price_filter.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Product, Tenant
from tests.conftest import make_test_settings


def _make_mock_product():
    p = MagicMock(spec=Product)
    p.id = uuid4()
    p.platform_id = "prod-1"
    p.title = "Serum"
    p.price_minor = 2500
    p.currency = "USD"
    p.in_stock = True
    p.categories = ["serum"]
    p.domain_attributes = {}
    return p


def test_search_with_price_filter_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_rows = [(_make_mock_product(), 0.91)]

    with (
        patch(
            "eshopeo.api.routers.search.embed_query",
            new_callable=AsyncMock,
            return_value=[0.1] * 1024,
        ),
        patch(
            "eshopeo.api.routers.search.vector_search_products",
            new_callable=AsyncMock,
            return_value=mock_rows,
        ),
    ):
        r = client.get("/v1/search/products?q=serum&min_price=1000&max_price=5000")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert len(r.json()["results"]) == 1


def test_search_price_filter_passed_to_crud():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_search = AsyncMock(return_value=[])

    with (
        patch("eshopeo.api.routers.search.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024),
        patch("eshopeo.api.routers.search.vector_search_products", mock_search),
    ):
        client.get("/v1/search/products?q=serum&min_price=1000&max_price=5000")

    app.dependency_overrides.clear()

    _, kwargs = mock_search.call_args
    assert kwargs.get("min_price") == 1000
    assert kwargs.get("max_price") == 5000


def test_search_price_filter_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/search/products?q=serum&min_price=1000")

    assert r.status_code == 401
```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core"; python -m py_compile eshopeo/db/crud/products.py eshopeo/api/routers/search.py tests/test_search_price_filter.py
```

### Step 5: Commit

```bash
git add services/core/eshopeo/db/crud/products.py services/core/eshopeo/api/routers/search.py services/core/tests/test_search_price_filter.py
git commit -m "feat: price range filters on semantic search GET /v1/search/products"
```

---

## Task P13-2: Product browse endpoint

**Files:**
- Modify: `services/core/eshopeo/db/crud/products.py`
- Modify: `services/core/eshopeo/api/routers/search.py`
- Create: `services/core/tests/test_search_browse.py`

### Step 1: Add `browse_products` to `products.py`

Read the file first. `func`, `select`, `Product`, `UUID`, `AsyncSession` are already imported.

Add at the end of the file (after `list_products_without_embedding` if that was added, otherwise at the very end):

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

### Step 2: Add browse endpoint to `search.py`

Read the file. Add `browse_products` to the import from `eshopeo.db.crud.products`:
```python
from eshopeo.db.crud.products import browse_products, get_similar_products, suggest_product_titles, vector_search_products
```

Add new models (after `SuggestResponse`):
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

Add endpoint (before the `GET /products` endpoint so route ordering is correct):
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

### Step 3: Create `test_search_browse.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Product, Tenant
from tests.conftest import make_test_settings


def _make_mock_product():
    p = MagicMock(spec=Product)
    p.id = uuid4()
    p.platform_id = "prod-1"
    p.title = "Toner"
    p.price_minor = 1800
    p.currency = "USD"
    p.in_stock = True
    p.categories = ["toner"]
    p.domain_attributes = {}
    return p


def test_browse_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_products = [_make_mock_product(), _make_mock_product()]

    with patch(
        "eshopeo.api.routers.search.browse_products",
        new_callable=AsyncMock,
        return_value=(mock_products, 2),
    ):
        r = client.get("/v1/search/browse")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["products"]) == 2
    assert data["total"] == 2


def test_browse_empty_catalog():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch(
        "eshopeo.api.routers.search.browse_products",
        new_callable=AsyncMock,
        return_value=([], 0),
    ):
        r = client.get("/v1/search/browse")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["products"] == []
    assert r.json()["total"] == 0


def test_browse_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/search/browse")

    assert r.status_code == 401
```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core"; python -m py_compile eshopeo/db/crud/products.py eshopeo/api/routers/search.py tests/test_search_browse.py
```

### Step 5: Commit

```bash
git add services/core/eshopeo/db/crud/products.py services/core/eshopeo/api/routers/search.py services/core/tests/test_search_browse.py
git commit -m "feat: product browse endpoint GET /v1/search/browse"
```

---

## Task P13-3: Bulk re-embedding trigger

**Files:**
- Modify: `services/core/eshopeo/db/crud/products.py`
- Modify: `services/core/eshopeo/api/routers/jobs.py`
- Create: `services/core/tests/test_bulk_embed.py`

### Step 1: Add `list_products_without_embedding` to `products.py`

Read the file first. Add at the end:

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

### Step 2: Add endpoint to `jobs.py`

Read the file first. Add imports at the top (after existing imports):

```python
from eshopeo.db.crud.products import list_products_without_embedding
from eshopeo.workers.tasks.embedding import embed_product
```

Add model and endpoint (before the existing `GET /{job_id}` endpoint — `POST /embed/bulk` is a POST so there's no route conflict, but placing it first is cleaner):

```python
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

### Step 3: Create `test_bulk_embed.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Product, Tenant
from tests.conftest import make_test_settings


def _make_mock_product():
    p = MagicMock(spec=Product)
    p.id = uuid4()
    p.tenant_id = uuid4()
    return p


def test_bulk_embed_queues_products():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_products = [_make_mock_product() for _ in range(3)]
    mock_delay = MagicMock()

    with (
        patch(
            "eshopeo.api.routers.jobs.list_products_without_embedding",
            new_callable=AsyncMock,
            return_value=mock_products,
        ),
        patch(
            "eshopeo.api.routers.jobs.embed_product",
            delay=mock_delay,
        ) as mock_task,
    ):
        mock_task.delay = mock_delay
        r = client.post("/v1/jobs/embed/bulk")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["queued"] == 3
    assert mock_delay.call_count == 3


def test_bulk_embed_no_products():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with (
        patch(
            "eshopeo.api.routers.jobs.list_products_without_embedding",
            new_callable=AsyncMock,
            return_value=[],
        ),
        patch("eshopeo.api.routers.jobs.embed_product"),
    ):
        r = client.post("/v1/jobs/embed/bulk")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["queued"] == 0


def test_bulk_embed_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.post("/v1/jobs/embed/bulk")

    assert r.status_code == 401
```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core"; python -m py_compile eshopeo/db/crud/products.py eshopeo/api/routers/jobs.py tests/test_bulk_embed.py
```

### Step 5: Commit

```bash
git add services/core/eshopeo/db/crud/products.py services/core/eshopeo/api/routers/jobs.py services/core/tests/test_bulk_embed.py
git commit -m "feat: bulk re-embedding trigger POST /v1/jobs/embed/bulk"
```

---

## Task P13-4: Full suite + PROGRESS.md

Update `docs/PROGRESS.md`:
- Status: Phase 13 complete, 220/220 tests pass (211 prior + 3 + 3 + 3 = 220)
- Add Phase 13 section and session log entry

```bash
git add docs/PROGRESS.md && git commit -m "docs: Phase 13 complete — 220 tests"
```
