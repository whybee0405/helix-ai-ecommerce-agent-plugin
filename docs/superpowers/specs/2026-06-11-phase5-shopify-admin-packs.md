# Phase 5 — Shopify Orders, Admin Stats & Pack API Design Spec

**Date:** 2026-06-11  
**Status:** Approved  
**Scope:** Close Shopify order loop; platform admin visibility; pack discovery API; search category filter  
**Definition of done:** Shopify stores receive order events; operators can see platform-wide stats; available packs are listable via API; search supports category filtering.

---

## 1. Gap analysis from Phase 4

| Gap | Impact |
|-----|--------|
| Shopify order events unhandled | Shopify-connected stores can't track order data in real time |
| No platform-wide operator view | Platform operators have no visibility into aggregate usage |
| Packs not discoverable via API | Operators must inspect the file system to know what packs are loaded |
| Search has no category filter | Stores can't narrow results to a specific product category |

---

## 2. Shopify orders webhook (P5-1)

`POST /v1/webhooks/shopify/orders` — mirrors Shopify products webhook pattern.

### Translator: `helix/connectors/shopify.py`

New function `translate_shopify_order(payload, tenant_id) -> CanonicalOrder`:

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

### Router endpoint

Extend `helix/api/routers/shopify_webhooks.py`:
- `POST /v1/webhooks/shopify/orders`
- Same HMAC verification as products webhook (`verify_shopify_webhook`)
- Same auth pattern: `X-Helix-Tenant-Id` + `X-Shopify-Hmac-Sha256`
- Call `upsert_order(db, order_obj)` then `db.commit()`

---

## 3. Admin platform stats (P5-2)

`GET /v1/admin/stats` — auth: `X-Helix-Provision-Key`

Returns platform-wide aggregate data. All queries cross-tenant (no tenant_id filter).

Response:
```json
{
  "total_tenants": 12,
  "total_products": 4521,
  "total_customers": 890,
  "queries_this_month": 15432,
  "cost_this_month_usd": 12.45
}
```

### New CRUD: `helix/db/crud/admin.py`

```python
async def get_platform_stats(session, year_month_start, year_month_end) -> dict:
    # COUNT(tenant), COUNT(product), COUNT(customer)
    # SUM(usage_event.cost_usd) WHERE created_at in [start, end]
```

### New router: `helix/api/routers/admin.py`

- `GET /v1/admin/stats` — provision key auth (same `_auth_provision_key` pattern from tenants router)
- Default period: current month (first day to today)
- Returns `PlatformStats` Pydantic model

---

## 4. Pack listing API (P5-3)

Operators need to know what packs are loaded without reading the file system.

`GET /v1/packs` — auth: `X-Helix-Tenant-Key`

Response:
```json
[
  {
    "id": "kbeauty",
    "display_name": "K-Beauty",
    "version": "1.0"
  }
]
```

`GET /v1/packs/{pack_id}` — auth: `X-Helix-Tenant-Key`

Response:
```json
{
  "id": "kbeauty",
  "display_name": "K-Beauty",
  "version": "1.0",
  "compatibility_rules_count": 8,
  "routine_steps": ["cleanser", "toner", "serum", "moisturiser", "sunscreen"],
  "copy_locales": ["en"]
}
```

Returns `404` if pack not in registry.

### New router: `helix/api/routers/packs.py`

- Uses `_registry` from `helix.packs.registry` directly (read-only)
- No DB calls needed

---

## 5. Search category filter (P5-4)

Add `category: str | None = None` query param to `GET /v1/search/products`.

When provided, filter results to products where the category appears in `Product.categories` (JSONB array).

### Update `vector_search_products` in `helix/db/crud/products.py`

Add `category: str | None = None` parameter. When set, add filter:
```python
if category:
    filters.append(Product.categories.contains([category]))
```

### Update `GET /v1/search/products` in `helix/api/routers/search.py`

Add `category: str | None = Query(default=None)` param. Pass to `vector_search_products`.

---

## 6. File map

**New files:**
- `services/core/helix/db/crud/admin.py`
- `services/core/helix/api/routers/admin.py`
- `services/core/helix/api/routers/packs.py`

**Modified files:**
- `services/core/helix/connectors/shopify.py` — add `translate_shopify_order()`
- `services/core/helix/api/routers/shopify_webhooks.py` — add orders endpoint
- `services/core/helix/db/crud/products.py` — add `category` filter to `vector_search_products`
- `services/core/helix/api/routers/search.py` — add `category` query param
- `services/core/helix/api/app.py` — register admin and packs routers

**New tests:**
- `services/core/tests/test_shopify_order_webhook.py` (4 tests)
- `services/core/tests/test_admin_stats.py` (3 tests)
- `services/core/tests/test_packs_endpoint.py` (4 tests)
- `services/core/tests/test_search_category.py` (3 tests)

---

## 7. Security constraints

- Admin stats endpoint requires provision key — cross-tenant data exposure is intentional for operator role
- Pack listing exposes pack metadata (schema structure, rule counts) but not prompts or PII
- Search category filter: tenant isolation unchanged — category filter layered on top of existing `tenant_id` scope
- Shopify order webhook: same HMAC verification as products webhook; no new auth surface

---

## 8. Cost impact

- Admin stats: 3 COUNT queries + 1 aggregation — negligible DB cost, no LLM calls
- Pack listing: purely in-memory from `_registry` — zero cost
- Search category filter: adds one JSONB containment check to existing pgvector query — negligible DB cost
