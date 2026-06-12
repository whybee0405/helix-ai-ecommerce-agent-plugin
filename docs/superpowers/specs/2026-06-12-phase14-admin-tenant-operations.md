# Phase 14 — Admin Tenant Operations Design Spec

**Date:** 2026-06-12
**Status:** Approved
**Scope:** Operator-facing tenant management: list all tenants, view per-tenant usage, reset a tenant's quota counter.
**Definition of done:** Platform operators can enumerate all tenants, inspect monthly usage for a specific tenant, and reset a tenant's quota when needed — all authenticated via provision key.

---

## 1. Gap analysis from Phase 13

| Gap | Impact |
|-----|--------|
| No way to list all tenants via API | Operators must query the DB directly to see which tenants are provisioned |
| No per-tenant usage visibility in admin | `GET /v1/admin/stats` is cross-tenant only; no drill-down per tenant |
| No quota reset | When a tenant hits quota incorrectly or needs a grace reset, operators must manually delete the Redis key |

**Already done:** `GET /health` checks DB and Redis. Admin stats `GET /v1/admin/stats` exists. Auth: `X-Helix-Provision-Key` header (`_auth_provision` dependency).

---

## 2. List all tenants + detail (P14-1)

### New CRUD in `tenants.py`

```python
async def list_tenants(
    session: AsyncSession,
    limit: int = 20,
    offset: int = 0,
) -> list[Tenant]:
    result = await session.execute(
        select(Tenant).order_by(Tenant.created_at.desc()).limit(limit).offset(offset)
    )
    return list(result.scalars().all())


async def count_tenants(session: AsyncSession) -> int:
    result = await session.execute(select(func.count(Tenant.id)))
    return result.scalar_one()
```

No `tenant_id` scoping — these are cross-tenant admin operations.

### New endpoints in `admin.py`

**`GET /v1/admin/tenants`** — paginated list

Query params: `limit: int = 20` (ge=1, le=100), `offset: int = 0` (ge=0)

Response model `TenantOut`:
```json
{
  "id": "uuid",
  "name": "Glow Store",
  "platform": "shopify",
  "store_url": "https://glow.myshopify.com",
  "public_key": "uuid",
  "pack_id": "kbeauty",
  "created_at": "2026-06-01T10:00:00+00:00"
}
```

**Note:** `credentials_enc` is NEVER included in responses.

Response wrapper `TenantListResponse`: `{tenants: list[TenantOut], total: int, limit: int, offset: int}`

**`GET /v1/admin/tenants/{tenant_id}`** — single tenant

Returns `TenantOut`, 404 if not found.

Both auth via `_auth_provision` dependency.

### Tests — `test_admin_tenant_list.py` (3 tests)

Auth pattern: override `_auth_provision` dependency:
```python
from helix.api.routers.admin import _auth_provision
app.dependency_overrides[_auth_provision] = lambda: "test-key"
```

1. `test_admin_tenant_list_returns_200` — mock `list_tenants` + `count_tenants`; assert 200 + tenants length + total
2. `test_admin_tenant_detail_returns_200` — mock `get_tenant_by_id`; assert 200 + id matches
3. `test_admin_tenant_detail_404_on_unknown` — mock returns `None`; assert 404

---

## 3. Per-tenant usage summary (P14-2)

### New CRUD in `admin.py`

```python
async def get_tenant_usage_summary(
    session: AsyncSession,
    tenant_id: UUID,
    month_start: datetime,
    month_end: datetime,
) -> dict:
    row = (
        await session.execute(
            select(
                func.count(UsageEvent.id).label("total_queries"),
                func.coalesce(func.sum(UsageEvent.cost_usd), 0.0).label("total_cost_usd"),
                func.coalesce(func.sum(UsageEvent.tokens_in), 0).label("total_tokens_in"),
                func.coalesce(func.sum(UsageEvent.tokens_out), 0).label("total_tokens_out"),
            ).where(
                UsageEvent.tenant_id == tenant_id,
                UsageEvent.created_at >= month_start,
                UsageEvent.created_at <= month_end,
            )
        )
    ).one()
    return {
        "total_queries": row.total_queries or 0,
        "total_cost_usd": round(float(row.total_cost_usd or 0), 6),
        "total_tokens_in": row.total_tokens_in or 0,
        "total_tokens_out": row.total_tokens_out or 0,
    }
```

`UsageEvent` fields: `tenant_id`, `model`, `tokens_in`, `tokens_out`, `cost_usd`, `created_at`.

### New endpoint in `admin.py`

**`GET /v1/admin/tenants/{tenant_id}/usage`**

Query params: optional `month` as `YYYY-MM` string (default: current month)

Response:
```json
{
  "tenant_id": "uuid",
  "month": "2026-06",
  "total_queries": 42,
  "total_cost_usd": 0.031200,
  "total_tokens_in": 15000,
  "total_tokens_out": 6000
}
```

Parse `month` param → derive `month_start` and `month_end` datetime objects. If not provided, use current month.

### Tests — `test_admin_tenant_usage.py` (3 tests)

1. `test_admin_tenant_usage_returns_200` — mock `get_tenant_usage_summary`; assert 200 + all keys
2. `test_admin_tenant_usage_requires_provision_key` — no override; assert 401
3. `test_admin_tenant_usage_zero_when_no_events` — mock returns zeros; assert `total_queries == 0`

---

## 4. Tenant quota reset (P14-3)

### New endpoint in `admin.py`

**`POST /v1/admin/tenants/{tenant_id}/quota/reset`**

No new CRUD — Redis access directly in the endpoint.

```python
import redis.asyncio as aioredis

@router.post("/tenants/{tenant_id}/quota/reset")
async def reset_tenant_quota(
    tenant_id: UUID,
    _: str = Depends(_auth_provision),
) -> dict:
    settings = get_settings()
    today = datetime.now(timezone.utc)
    key = f"quota:{tenant_id}:{today.year}-{today.month:02d}"
    r = aioredis.from_url(str(settings.redis_url))
    try:
        await r.delete(key)
    finally:
        await r.aclose()
    return {"reset": True, "key": key}
```

Returns 200 with `{"reset": true, "key": "quota:uuid:2026-06"}`.

### Tests — `test_admin_quota_reset.py` (3 tests)

Mock `aioredis.from_url` at `helix.api.routers.admin.aioredis`:

```python
with patch("helix.api.routers.admin.aioredis") as mock_aioredis:
    mock_redis = AsyncMock()
    mock_aioredis.from_url.return_value = mock_redis
    r = client.post(f"/v1/admin/tenants/{tenant_id}/quota/reset")
```

1. `test_quota_reset_returns_200` — mock Redis; assert 200 + `reset == True` + `key` contains tenant_id
2. `test_quota_reset_calls_delete` — mock Redis; assert `mock_redis.delete.called`
3. `test_quota_reset_requires_provision_key` — no provision override; assert 401

---

## 5. File map

**Modified files:**
- `services/core/helix/db/crud/tenants.py` — add `list_tenants`, `count_tenants`
- `services/core/helix/db/crud/admin.py` — add `get_tenant_usage_summary`
- `services/core/helix/api/routers/admin.py` — add 4 new endpoints + models

**New files:**
- `services/core/tests/test_admin_tenant_list.py` (3 tests)
- `services/core/tests/test_admin_tenant_usage.py` (3 tests)
- `services/core/tests/test_admin_quota_reset.py` (3 tests)

---

## 6. Security constraints

- All admin endpoints auth via `_auth_provision` (provision key) — not `get_tenant`
- `credentials_enc` NEVER returned in `TenantOut` — it contains encrypted secrets
- Quota key deletion is scoped to the specific tenant_id in the URL path
- Redis client closed in `finally` block — no connection leaks
