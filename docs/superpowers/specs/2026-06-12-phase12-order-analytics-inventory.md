# Phase 12 — Order Analytics & Inventory Insights Design Spec

**Date:** 2026-06-12
**Status:** Approved
**Scope:** Merchant-facing order revenue analytics (total, average, date range) and order status breakdown; product inventory snapshot (in_stock / out_of_stock split).
**Definition of done:** Merchants can see total revenue and average order value over a date range; see how orders break down by fulfillment status; see current inventory health at a glance.

---

## 1. Gap analysis from Phase 11

| Gap | Impact |
|-----|--------|
| No order revenue visibility | Merchants can't measure GMV through the platform; can't attribute AI-assisted sessions to revenue |
| No order status breakdown | Merchants can't see paid vs pending vs refunded ratios |
| No inventory health endpoint | Merchants can't tell at a glance how much of their catalog is in-stock vs. out-of-stock |

**Why no line_items unnesting:** `line_items` stores raw platform payloads (Shopify / WooCommerce) with different schemas. Aggregating on structured columns (`total_minor`, `status`, `placed_at`) is platform-agnostic and reliable.

---

## 2. Order analytics (P12-1)

### New CRUD: `get_order_analytics` in `orders.py`

```python
async def get_order_analytics(
    session: AsyncSession,
    tenant_id: UUID,
    start: date | None = None,
    end: date | None = None,
) -> dict:
    # COUNT(*) and SUM/AVG on total_minor, scoped by tenant_id + optional date range on placed_at
    # Returns: total_orders, total_revenue_minor, avg_order_value_minor
    # avg_order_value_minor = round(total / count) if count > 0 else 0
```

Implementation:
```python
from datetime import date, datetime, timedelta, timezone
from sqlalchemy import func

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

### New endpoint in `analytics.py`

```
GET /v1/analytics/orders
```

Auth: `get_tenant`
Query params: optional `start_date: date`, `end_date: date` (default: last 30 days)

Response:
```json
{
  "period": {"start": "2026-05-12", "end": "2026-06-12"},
  "total_orders": 87,
  "total_revenue_minor": 218500,
  "avg_order_value_minor": 2511
}
```

### Tests — `test_order_analytics.py` (3 tests)

1. `test_order_analytics_returns_200` — mock `get_order_analytics`; assert 200 + all keys present
2. `test_order_analytics_requires_auth` — 401
3. `test_order_analytics_zero_orders` — mock returns `{total_orders: 0, total_revenue_minor: 0, avg_order_value_minor: 0}`; assert 200

---

## 3. Orders by status (P12-2)

### New CRUD: `get_orders_by_status` in `orders.py`

```python
async def get_orders_by_status(
    session: AsyncSession,
    tenant_id: UUID,
    start: date | None = None,
    end: date | None = None,
) -> list[dict]:
    # GROUP BY status, count(*), sum(total_minor)
    # Returns [{"status": str, "count": int, "total_revenue_minor": int}, ...]
    # ordered by count DESC
```

Implementation:
```python
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

### New endpoint in `analytics.py`

```
GET /v1/analytics/orders/by-status
```

Auth: `get_tenant`
Query params: optional `start_date: date`, `end_date: date`

Response:
```json
{
  "statuses": [
    {"status": "paid", "count": 72, "total_revenue_minor": 184320},
    {"status": "pending", "count": 10, "total_revenue_minor": 25100},
    {"status": "refunded", "count": 5, "total_revenue_minor": 9080}
  ]
}
```

### Tests — `test_orders_by_status.py` (3 tests)

1. `test_orders_by_status_returns_200` — mock `get_orders_by_status` returning 2 items; assert 200 + list length + first item fields
2. `test_orders_by_status_requires_auth` — 401
3. `test_orders_by_status_empty` — mock returns `[]`; assert `statuses: []`

---

## 4. Inventory snapshot (P12-3)

### New CRUD: `get_inventory_snapshot` in `products.py`

```python
async def get_inventory_snapshot(
    session: AsyncSession,
    tenant_id: UUID,
) -> dict:
    # COUNT(*) total, COUNT WHERE in_stock=true, COUNT WHERE in_stock=false
    # Returns: total, in_stock, out_of_stock, in_stock_rate
```

Implementation:
```python
from sqlalchemy import case, func

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
return {"total": total, "in_stock": in_stock, "out_of_stock": out_of_stock, "in_stock_rate": in_stock_rate}
```

### New endpoint in `analytics.py`

```
GET /v1/analytics/products/inventory
```

Auth: `get_tenant`

Response:
```json
{
  "total": 150,
  "in_stock": 142,
  "out_of_stock": 8,
  "in_stock_rate": 0.95
}
```

### Tests — `test_inventory_snapshot.py` (3 tests)

1. `test_inventory_snapshot_returns_200` — mock returns full data; assert 200 + all fields
2. `test_inventory_snapshot_requires_auth` — 401
3. `test_inventory_snapshot_zero_products` — mock returns `{total: 0, in_stock: 0, out_of_stock: 0, in_stock_rate: 1.0}`; assert 200

---

## 5. File map

**Modified files:**
- `services/core/helix/db/crud/orders.py` — add `get_order_analytics`, `get_orders_by_status`
- `services/core/helix/db/crud/products.py` — add `get_inventory_snapshot`
- `services/core/helix/api/routers/analytics.py` — add 3 new endpoints

**New files:**
- `services/core/tests/test_order_analytics.py` (3 tests)
- `services/core/tests/test_orders_by_status.py` (3 tests)
- `services/core/tests/test_inventory_snapshot.py` (3 tests)

---

## 6. Security constraints

- All CRUD queries scoped by `tenant_id`
- `total_revenue_minor` is stored integers (no floating point) — no precision risk
- No customer PII in aggregated responses
