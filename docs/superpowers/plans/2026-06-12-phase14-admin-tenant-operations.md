# Phase 14 — Admin Tenant Operations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Operator-facing admin endpoints: list all tenants, per-tenant usage, and quota reset.

**Architecture:** Two new CRUD functions in `tenants.py` (`list_tenants`, `count_tenants`); one new CRUD in `admin.py` (`get_tenant_usage_summary`); four new endpoints added to `admin.py` router. No new models, no migration.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy 2.x async, redis.asyncio, pytest (asyncio_mode=auto)

---

## Context for all tasks

- `asyncio_mode = "auto"` — NEVER add `@pytest.mark.asyncio`
- Admin auth: `_auth_provision` dependency (not `get_tenant`)
- Override in tests: `from helix.api.routers.admin import _auth_provision` then `app.dependency_overrides[_auth_provision] = lambda: "test-key"`
- 401 test: do NOT set `_auth_provision` override — call without `X-Helix-Provision-Key` header
- `credentials_enc` MUST NOT appear in any response model
- `Tenant` model fields: `id`, `name`, `platform`, `store_url`, `credentials_enc` (secret — never expose), `public_key`, `created_at`, `pack_id`
- `UsageEvent` fields: `tenant_id`, `model`, `tokens_in`, `tokens_out`, `cost_usd`, `created_at`
- Redis key format: `f"quota:{tenant_id}:{today.year}-{today.month:02d}"`
- Patch target for Redis: `helix.api.routers.admin.aioredis`

---

## Task P14-1: List all tenants + tenant detail

**Files:**
- Modify: `services/core/helix/db/crud/tenants.py`
- Modify: `services/core/helix/api/routers/admin.py`
- Create: `services/core/tests/test_admin_tenant_list.py`

### Step 1: Add CRUD to `tenants.py`

Read the file first. `select`, `UUID`, `AsyncSession`, `Tenant` already imported. Add `func` to the `from sqlalchemy import select` line.

Add at the end:

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

### Step 2: Add endpoints to `admin.py`

Read the file first. Add imports:
```python
from typing import Annotated
from uuid import UUID
from helix.db.crud.tenants import count_tenants, get_tenant_by_id, list_tenants
```

Note: `Annotated` may already be imported — check before adding. `UUID` is likely not yet imported in admin.py.

Add models (before the `PlatformStats` model or after it):

```python
class TenantOut(BaseModel):
    id: str
    name: str
    platform: str
    store_url: str
    public_key: str
    pack_id: str | None
    created_at: str


class TenantListResponse(BaseModel):
    tenants: list[TenantOut]
    total: int
    limit: int
    offset: int
```

Add a helper to convert `Tenant` → `TenantOut`:
```python
def _tenant_out(tenant) -> TenantOut:
    return TenantOut(
        id=str(tenant.id),
        name=tenant.name,
        platform=tenant.platform,
        store_url=tenant.store_url,
        public_key=str(tenant.public_key),
        pack_id=tenant.pack_id,
        created_at=tenant.created_at.isoformat(),
    )
```

Add endpoints (after the existing `admin_stats` endpoint):

```python
@router.get("/tenants", response_model=TenantListResponse)
async def list_tenants_endpoint(
    limit: Annotated[int, Query(ge=1, le=100)] = 20,
    offset: Annotated[int, Query(ge=0)] = 0,
    _: str = Depends(_auth_provision),
    db: AsyncSession = Depends(get_db),
) -> TenantListResponse:
    tenants = await list_tenants(db, limit=limit, offset=offset)
    total = await count_tenants(db)
    return TenantListResponse(
        tenants=[_tenant_out(t) for t in tenants],
        total=total,
        limit=limit,
        offset=offset,
    )


@router.get("/tenants/{tenant_id}", response_model=TenantOut)
async def get_tenant_endpoint(
    tenant_id: UUID,
    _: str = Depends(_auth_provision),
    db: AsyncSession = Depends(get_db),
) -> TenantOut:
    tenant = await get_tenant_by_id(db, tenant_id)
    if tenant is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Tenant not found")
    return _tenant_out(tenant)
```

Add `Query` to the `fastapi` import line and `status` if not already present. Add `AsyncSession` to `sqlalchemy.ext.asyncio` import if needed.

### Step 3: Create `test_admin_tenant_list.py`

```python
from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.routers.admin import _auth_provision
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def _make_mock_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    t.name = "Test Store"
    t.platform = "shopify"
    t.store_url = "https://test.myshopify.com"
    t.public_key = uuid4()
    t.pack_id = "kbeauty"
    t.created_at = datetime(2026, 6, 1, tzinfo=timezone.utc)
    return t


def test_admin_tenant_list_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    app.dependency_overrides[_auth_provision] = lambda: "test-key"

    mock_tenants = [_make_mock_tenant(), _make_mock_tenant()]

    with (
        patch(
            "helix.api.routers.admin.list_tenants",
            new_callable=AsyncMock,
            return_value=mock_tenants,
        ),
        patch(
            "helix.api.routers.admin.count_tenants",
            new_callable=AsyncMock,
            return_value=2,
        ),
    ):
        r = client.get("/v1/admin/tenants")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["tenants"]) == 2
    assert data["total"] == 2
    assert "credentials_enc" not in data["tenants"][0]


def test_admin_tenant_detail_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    app.dependency_overrides[_auth_provision] = lambda: "test-key"

    mock_tenant = _make_mock_tenant()
    tenant_id = mock_tenant.id

    with patch(
        "helix.api.routers.admin.get_tenant_by_id",
        new_callable=AsyncMock,
        return_value=mock_tenant,
    ):
        r = client.get(f"/v1/admin/tenants/{tenant_id}")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["id"] == str(tenant_id)
    assert "credentials_enc" not in r.json()


def test_admin_tenant_detail_404_on_unknown():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    app.dependency_overrides[_auth_provision] = lambda: "test-key"

    with patch(
        "helix.api.routers.admin.get_tenant_by_id",
        new_callable=AsyncMock,
        return_value=None,
    ):
        r = client.get(f"/v1/admin/tenants/{uuid4()}")

    app.dependency_overrides.clear()

    assert r.status_code == 404
```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core"; python -m py_compile helix/db/crud/tenants.py helix/api/routers/admin.py tests/test_admin_tenant_list.py
```

### Step 5: Commit

```bash
git add services/core/helix/db/crud/tenants.py services/core/helix/api/routers/admin.py services/core/tests/test_admin_tenant_list.py
git commit -m "feat: admin tenant list and detail GET /v1/admin/tenants"
```

---

## Task P14-2: Per-tenant usage summary

**Files:**
- Modify: `services/core/helix/db/crud/admin.py`
- Modify: `services/core/helix/api/routers/admin.py`
- Create: `services/core/tests/test_admin_tenant_usage.py`

### Step 1: Add `get_tenant_usage_summary` to `admin.py` CRUD

Read the file. `func`, `select`, `AsyncSession`, `UsageEvent`, `datetime` are already imported. Add `UUID` to the `from uuid import UUID` (or add the import if not there).

Add at the end:

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

### Step 2: Add endpoint to `admin.py` router

Read the file. Add `get_tenant_usage_summary` to the admin CRUD import:
```python
from helix.db.crud.admin import get_platform_stats, get_tenant_usage_summary
```

Add model and endpoint (after `get_tenant_endpoint`):

```python
class TenantUsageSummary(BaseModel):
    tenant_id: str
    month: str
    total_queries: int
    total_cost_usd: float
    total_tokens_in: int
    total_tokens_out: int


@router.get("/tenants/{tenant_id}/usage", response_model=TenantUsageSummary)
async def get_tenant_usage_endpoint(
    tenant_id: UUID,
    month: str | None = Query(default=None, description="YYYY-MM format, defaults to current month"),
    _: str = Depends(_auth_provision),
    db: AsyncSession = Depends(get_db),
) -> TenantUsageSummary:
    today = datetime.now(timezone.utc)
    if month:
        year, mon = int(month.split("-")[0]), int(month.split("-")[1])
    else:
        year, mon = today.year, today.month
    month_start = datetime(year, mon, 1, tzinfo=timezone.utc)
    import calendar
    last_day = calendar.monthrange(year, mon)[1]
    month_end = datetime(year, mon, last_day, 23, 59, 59, tzinfo=timezone.utc)
    usage = await get_tenant_usage_summary(db, tenant_id, month_start, month_end)
    return TenantUsageSummary(
        tenant_id=str(tenant_id),
        month=f"{year}-{mon:02d}",
        **usage,
    )
```

### Step 3: Create `test_admin_tenant_usage.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.routers.admin import _auth_provision
from tests.conftest import make_test_settings


def test_admin_tenant_usage_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    app.dependency_overrides[_auth_provision] = lambda: "test-key"

    tenant_id = uuid4()
    mock_usage = {
        "total_queries": 42,
        "total_cost_usd": 0.031200,
        "total_tokens_in": 15000,
        "total_tokens_out": 6000,
    }

    with patch(
        "helix.api.routers.admin.get_tenant_usage_summary",
        new_callable=AsyncMock,
        return_value=mock_usage,
    ):
        r = client.get(f"/v1/admin/tenants/{tenant_id}/usage")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["total_queries"] == 42
    assert data["tenant_id"] == str(tenant_id)
    assert "month" in data


def test_admin_tenant_usage_requires_provision_key():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get(f"/v1/admin/tenants/{uuid4()}/usage")

    assert r.status_code == 401


def test_admin_tenant_usage_zero_when_no_events():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    app.dependency_overrides[_auth_provision] = lambda: "test-key"

    mock_usage = {
        "total_queries": 0,
        "total_cost_usd": 0.0,
        "total_tokens_in": 0,
        "total_tokens_out": 0,
    }

    with patch(
        "helix.api.routers.admin.get_tenant_usage_summary",
        new_callable=AsyncMock,
        return_value=mock_usage,
    ):
        r = client.get(f"/v1/admin/tenants/{uuid4()}/usage")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["total_queries"] == 0
```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core"; python -m py_compile helix/db/crud/admin.py helix/api/routers/admin.py tests/test_admin_tenant_usage.py
```

### Step 5: Commit

```bash
git add services/core/helix/db/crud/admin.py services/core/helix/api/routers/admin.py services/core/tests/test_admin_tenant_usage.py
git commit -m "feat: per-tenant usage summary GET /v1/admin/tenants/{id}/usage"
```

---

## Task P14-3: Tenant quota reset

**Files:**
- Modify: `services/core/helix/api/routers/admin.py`
- Create: `services/core/tests/test_admin_quota_reset.py`

### Step 1: Add endpoint to `admin.py` router

Read the file. Add `import redis.asyncio as aioredis` at the top with other imports (or `import helix.api.routers.admin` already imports it via health — check first; if not, add it).

Add endpoint at the end of `admin.py`:

```python
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

### Step 2: Create `test_admin_quota_reset.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.routers.admin import _auth_provision
from tests.conftest import make_test_settings


def test_quota_reset_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    app.dependency_overrides[_auth_provision] = lambda: "test-key"

    tenant_id = uuid4()

    mock_redis = AsyncMock()
    with patch("helix.api.routers.admin.aioredis") as mock_aioredis:
        mock_aioredis.from_url.return_value = mock_redis
        r = client.post(f"/v1/admin/tenants/{tenant_id}/quota/reset")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["reset"] is True
    assert str(tenant_id) in data["key"]


def test_quota_reset_calls_delete():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    app.dependency_overrides[_auth_provision] = lambda: "test-key"

    tenant_id = uuid4()
    mock_redis = AsyncMock()

    with patch("helix.api.routers.admin.aioredis") as mock_aioredis:
        mock_aioredis.from_url.return_value = mock_redis
        client.post(f"/v1/admin/tenants/{tenant_id}/quota/reset")

    app.dependency_overrides.clear()

    mock_redis.delete.assert_called_once()


def test_quota_reset_requires_provision_key():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.post(f"/v1/admin/tenants/{uuid4()}/quota/reset")

    assert r.status_code == 401
```

### Step 3: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core"; python -m py_compile helix/api/routers/admin.py tests/test_admin_quota_reset.py
```

### Step 4: Commit

```bash
git add services/core/helix/api/routers/admin.py services/core/tests/test_admin_quota_reset.py
git commit -m "feat: tenant quota reset POST /v1/admin/tenants/{id}/quota/reset"
```

---

## Task P14-4: Full suite + PROGRESS.md

Update `docs/PROGRESS.md`:
- Status: Phase 14 complete, 229/229 tests pass (220 prior + 3 + 3 + 3 = 229)
- Add Phase 14 section and session log entry

```bash
git add docs/PROGRESS.md && git commit -m "docs: Phase 14 complete — 229 tests"
```
