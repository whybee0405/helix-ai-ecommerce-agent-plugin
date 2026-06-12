# Phase 12 — Order Analytics & Inventory Insights Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add order revenue analytics, order status breakdown, and inventory snapshot endpoints.

**Architecture:** Two new CRUD functions in `orders.py` (`get_order_analytics`, `get_orders_by_status`); one new CRUD in `products.py` (`get_inventory_snapshot`); three new endpoints added to `analytics.py`. No new models, no migration.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy 2.x async, pytest (asyncio_mode=auto)

---

## Context for all tasks

- `asyncio_mode = "auto"` — NEVER add `@pytest.mark.asyncio`
- Patch at namespace where name is USED (`helix.api.routers.analytics.X`)
- Tests call `app.dependency_overrides.clear()` after running (every test that sets overrides)
- `Order` model fields: `id`, `tenant_id`, `platform_id`, `customer_id`, `total_minor (int)`, `currency (str)`, `status (str)`, `line_items (JSONB)`, `placed_at (datetime with tz)`
- `Product` model fields include `in_stock: bool`
- `func.coalesce(func.sum(...), 0)` — needed because SUM of zero rows returns NULL in PostgreSQL
- `case((col == True, 1))` — SQLAlchemy 2.x conditional count pattern
- Do not add duplicate imports — check existing imports before adding

---

## Task P12-1: Order analytics

**Files:**
- Modify: `services/core/helix/db/crud/orders.py`
- Modify: `services/core/helix/api/routers/analytics.py`
- Create: `services/core/tests/test_order_analytics.py`

### Step 1: Add CRUD to `orders.py`

Read the file first to check existing imports. Then add at the end:

```python
from datetime import date, datetime, timedelta, timezone
from sqlalchemy import func


async def get_order_analytics(
    session: AsyncSession,
    tenant_id: UUID,
    start: date | None = None,
    end: date | None = None,
) -> dict:
    stmt = select(
        func.count(Order.id).label("total_orders"),
        func.coalesce(func.sum(Order.total_minor), 0).label("total_revenue_minor"),
    ).where(Order.tenant_id == tenant_id)

    if start:
        start_dt = datetime(start.year, start.month, start.day, tzinfo=timezone.utc)
        stmt = stmt.where(Order.placed_at >= start_dt)
    if end:
        end_dt = datetime(end.year, end.month, end.day, tzinfo=timezone.utc) + timedelta(days=1)
        stmt = stmt.where(Order.placed_at < end_dt)

    row = (await session.execute(stmt)).one()
    total = row.total_orders or 0
    revenue = row.total_revenue_minor or 0
    avg = round(revenue / total) if total > 0 else 0
    return {"total_orders": total, "total_revenue_minor": revenue, "avg_order_value_minor": avg}
```

Note: `select`, `UUID`, `AsyncSession`, `Order` are already imported. Add `datetime`, `date`, `timedelta`, `timezone` and `func` only if not already present.

### Step 2: Add endpoint to `analytics.py`

Read the file first. Add import alongside the other orders CRUD imports (or as a new import if none exist):

```python
from helix.db.crud.orders import get_order_analytics
```

Add models and endpoint at the end:

```python
class OrderAnalyticsResponse(BaseModel):
    period: dict
    total_orders: int
    total_revenue_minor: int
    avg_order_value_minor: int


@router.get("/orders", response_model=OrderAnalyticsResponse)
async def get_order_analytics_endpoint(
    start_date: date | None = Query(default=None),
    end_date: date | None = Query(default=None),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> OrderAnalyticsResponse:
    today = date.today()
    effective_start = start_date or (today - timedelta(days=30))
    effective_end = end_date or today
    analytics = await get_order_analytics(
        db, tenant.id, start=effective_start, end=effective_end
    )
    return OrderAnalyticsResponse(
        period={"start": effective_start.isoformat(), "end": effective_end.isoformat()},
        **analytics,
    )
```

`date`, `timedelta` are already imported in analytics.py (used by conversation analytics). `Query` is already imported.

### Step 3: Create `test_order_analytics.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def test_order_analytics_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_analytics = {
        "total_orders": 87,
        "total_revenue_minor": 218500,
        "avg_order_value_minor": 2511,
    }

    with patch(
        "helix.api.routers.analytics.get_order_analytics",
        new_callable=AsyncMock,
        return_value=mock_analytics,
    ):
        r = client.get("/v1/analytics/orders")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["total_orders"] == 87
    assert data["total_revenue_minor"] == 218500
    assert "period" in data


def test_order_analytics_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/orders")

    assert r.status_code == 401


def test_order_analytics_zero_orders():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_analytics = {
        "total_orders": 0,
        "total_revenue_minor": 0,
        "avg_order_value_minor": 0,
    }

    with patch(
        "helix.api.routers.analytics.get_order_analytics",
        new_callable=AsyncMock,
        return_value=mock_analytics,
    ):
        r = client.get("/v1/analytics/orders")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["total_orders"] == 0
    assert r.json()["avg_order_value_minor"] == 0
```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core"; python -m py_compile helix/db/crud/orders.py helix/api/routers/analytics.py tests/test_order_analytics.py
```

### Step 5: Commit

```bash
git add services/core/helix/db/crud/orders.py services/core/helix/api/routers/analytics.py services/core/tests/test_order_analytics.py
git commit -m "feat: order revenue analytics GET /v1/analytics/orders"
```

---

## Task P12-2: Orders by status breakdown

**Files:**
- Modify: `services/core/helix/db/crud/orders.py`
- Modify: `services/core/helix/api/routers/analytics.py`
- Create: `services/core/tests/test_orders_by_status.py`

### Step 1: Add CRUD to `orders.py`

Read the file first. Add at the end (after `get_order_analytics`). `datetime`, `date`, `timedelta`, `timezone`, `func`, `Order`, `UUID`, `AsyncSession`, `select` are all imported from P12-1.

```python
async def get_orders_by_status(
    session: AsyncSession,
    tenant_id: UUID,
    start: date | None = None,
    end: date | None = None,
) -> list[dict]:
    stmt = (
        select(
            Order.status,
            func.count(Order.id).label("count"),
            func.coalesce(func.sum(Order.total_minor), 0).label("total_revenue_minor"),
        )
        .where(Order.tenant_id == tenant_id)
    )
    if start:
        start_dt = datetime(start.year, start.month, start.day, tzinfo=timezone.utc)
        stmt = stmt.where(Order.placed_at >= start_dt)
    if end:
        end_dt = datetime(end.year, end.month, end.day, tzinfo=timezone.utc) + timedelta(days=1)
        stmt = stmt.where(Order.placed_at < end_dt)
    stmt = stmt.group_by(Order.status).order_by(func.count(Order.id).desc())
    result = await session.execute(stmt)
    return [
        {"status": row.status, "count": row.count, "total_revenue_minor": row.total_revenue_minor}
        for row in result.all()
    ]
```

### Step 2: Add endpoint to `analytics.py`

Update the orders import line to include `get_orders_by_status`:
```python
from helix.db.crud.orders import get_order_analytics, get_orders_by_status
```

Add models and endpoint at the end:

```python
class OrderStatusItem(BaseModel):
    status: str
    count: int
    total_revenue_minor: int


class OrdersByStatusResponse(BaseModel):
    statuses: list[OrderStatusItem]


@router.get("/orders/by-status", response_model=OrdersByStatusResponse)
async def get_orders_by_status_endpoint(
    start_date: date | None = Query(default=None),
    end_date: date | None = Query(default=None),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> OrdersByStatusResponse:
    statuses = await get_orders_by_status(
        db, tenant.id, start=start_date, end=end_date
    )
    return OrdersByStatusResponse(
        statuses=[OrderStatusItem(**s) for s in statuses]
    )
```

### Step 3: Create `test_orders_by_status.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def test_orders_by_status_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_statuses = [
        {"status": "paid", "count": 72, "total_revenue_minor": 184320},
        {"status": "pending", "count": 10, "total_revenue_minor": 25100},
    ]

    with patch(
        "helix.api.routers.analytics.get_orders_by_status",
        new_callable=AsyncMock,
        return_value=mock_statuses,
    ):
        r = client.get("/v1/analytics/orders/by-status")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["statuses"]) == 2
    assert data["statuses"][0]["status"] == "paid"
    assert data["statuses"][0]["count"] == 72


def test_orders_by_status_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/orders/by-status")

    assert r.status_code == 401


def test_orders_by_status_empty():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch(
        "helix.api.routers.analytics.get_orders_by_status",
        new_callable=AsyncMock,
        return_value=[],
    ):
        r = client.get("/v1/analytics/orders/by-status")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["statuses"] == []
```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core"; python -m py_compile helix/db/crud/orders.py helix/api/routers/analytics.py tests/test_orders_by_status.py
```

### Step 5: Commit

```bash
git add services/core/helix/db/crud/orders.py services/core/helix/api/routers/analytics.py services/core/tests/test_orders_by_status.py
git commit -m "feat: orders by status analytics GET /v1/analytics/orders/by-status"
```

---

## Task P12-3: Inventory snapshot

**Files:**
- Modify: `services/core/helix/db/crud/products.py`
- Modify: `services/core/helix/api/routers/analytics.py`
- Create: `services/core/tests/test_inventory_snapshot.py`

### Step 1: Add `get_inventory_snapshot` to `products.py`

Read the file first. `func`, `select`, `Product`, `UUID`, `AsyncSession` are already imported. Add `case` to the `from sqlalchemy import ...` line if it is not already there.

Add at the end of the file:

```python
async def get_inventory_snapshot(
    session: AsyncSession,
    tenant_id: UUID,
) -> dict:
    result = await session.execute(
        select(
            func.count(Product.id).label("total"),
            func.count(case((Product.in_stock == True, 1))).label("in_stock"),
            func.count(case((Product.in_stock == False, 1))).label("out_of_stock"),
        ).where(Product.tenant_id == tenant_id)
    )
    row = result.one()
    total = row.total or 0
    in_stock = row.in_stock or 0
    out_of_stock = row.out_of_stock or 0
    in_stock_rate = round(in_stock / total, 2) if total > 0 else 1.0
    return {
        "total": total,
        "in_stock": in_stock,
        "out_of_stock": out_of_stock,
        "in_stock_rate": in_stock_rate,
    }
```

### Step 2: Add endpoint to `analytics.py`

Update the products CRUD import line to include `get_inventory_snapshot`:
```python
from helix.db.crud.products import get_embedding_coverage, get_inventory_snapshot
```

Add models and endpoint at the end:

```python
class InventorySnapshot(BaseModel):
    total: int
    in_stock: int
    out_of_stock: int
    in_stock_rate: float


@router.get("/products/inventory", response_model=InventorySnapshot)
async def get_inventory_snapshot_endpoint(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> InventorySnapshot:
    snapshot = await get_inventory_snapshot(db, tenant.id)
    return InventorySnapshot(**snapshot)
```

### Step 3: Create `test_inventory_snapshot.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def test_inventory_snapshot_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_snapshot = {
        "total": 150,
        "in_stock": 142,
        "out_of_stock": 8,
        "in_stock_rate": 0.95,
    }

    with patch(
        "helix.api.routers.analytics.get_inventory_snapshot",
        new_callable=AsyncMock,
        return_value=mock_snapshot,
    ):
        r = client.get("/v1/analytics/products/inventory")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["total"] == 150
    assert data["in_stock"] == 142
    assert data["in_stock_rate"] == 0.95


def test_inventory_snapshot_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/products/inventory")

    assert r.status_code == 401


def test_inventory_snapshot_zero_products():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_snapshot = {
        "total": 0,
        "in_stock": 0,
        "out_of_stock": 0,
        "in_stock_rate": 1.0,
    }

    with patch(
        "helix.api.routers.analytics.get_inventory_snapshot",
        new_callable=AsyncMock,
        return_value=mock_snapshot,
    ):
        r = client.get("/v1/analytics/products/inventory")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["in_stock_rate"] == 1.0
```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core"; python -m py_compile helix/db/crud/products.py helix/api/routers/analytics.py tests/test_inventory_snapshot.py
```

### Step 5: Commit

```bash
git add services/core/helix/db/crud/products.py services/core/helix/api/routers/analytics.py services/core/tests/test_inventory_snapshot.py
git commit -m "feat: inventory snapshot GET /v1/analytics/products/inventory"
```

---

## Task P12-4: Full suite + PROGRESS.md

Update `docs/PROGRESS.md`:
- Status: Phase 12 complete, 211/211 tests pass (202 prior + 3 + 3 + 3 = 211)
- Add Phase 12 section and session log entry

```bash
git add docs/PROGRESS.md && git commit -m "docs: Phase 12 complete — 211 tests"
```
