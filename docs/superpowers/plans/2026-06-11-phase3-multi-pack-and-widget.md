# Phase 3 — Multi-Pack & Widget Embed Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable per-tenant domain packs, add tenant management and job status endpoints, and ship an embeddable storefront widget.

**Architecture:** Tenant model gains a nullable `pack_id` column (migration 0002); all pack lookups switch from `default_pack()` to `get_pack_for_tenant(tenant)`; new router for job status (uses existing Job model); Widget JS served as a vanilla-JS endpoint with no external dependencies.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy async, Alembic, pytest with asyncio_mode=auto

**Test suite baseline:** 81 tests passing at start of Phase 3.

---

### Task 1 (P3-1): Tenant `pack_id` column + Alembic migration

**Files:**
- Modify: `services/core/helix/db/models.py`
- Create: `services/core/helix/db/migrations/versions/0002_tenant_pack_id.py`
- Modify: `services/core/helix/api/routers/tenants.py`
- Test: `services/core/tests/test_tenant_pack.py` (first 2 tests only — pack routing tests come in Task 2)

**Context:**
- `Tenant` model is in `services/core/helix/db/models.py`
- Existing migration pattern: see `services/core/helix/db/migrations/versions/0001_initial.py`
- Current `ProvisionRequest` has `name`, `platform`, `store_url`, `credentials` — add optional `pack_id`
- `asyncio_mode = "auto"` in pyproject.toml — never add `@pytest.mark.asyncio`
- Test settings factory: `from tests.conftest import make_test_settings`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_tenant_pack.py
from helix.db.models import Tenant
from uuid import uuid4

def test_tenant_pack_id_defaults_none():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc", pack_id=None)
    assert t.pack_id is None

def test_tenant_pack_id_can_be_set():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc", pack_id="haircare")
    assert t.pack_id == "haircare"
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_tenant_pack.py -v
```
Expected: `AttributeError: pack_id`

- [ ] **Step 3: Add `pack_id` to Tenant model**

In `services/core/helix/db/models.py`, inside `class Tenant`, add after `created_at`:
```python
pack_id: Mapped[Optional[str]] = mapped_column(String, nullable=True)
```

- [ ] **Step 4: Create migration 0002**

Create `services/core/helix/db/migrations/versions/0002_tenant_pack_id.py`:
```python
"""Add pack_id to tenant

Revision ID: 0002
Revises: 0001
Create Date: 2026-06-11
"""
from typing import Sequence, Union
import sqlalchemy as sa
from alembic import op

revision: str = "0002"
down_revision: Union[str, None] = "0001"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column("tenant", sa.Column("pack_id", sa.String, nullable=True))


def downgrade() -> None:
    op.drop_column("tenant", "pack_id")
```

- [ ] **Step 5: Update provisioning endpoint to accept pack_id**

In `services/core/helix/api/routers/tenants.py`, update `ProvisionRequest`:
```python
class ProvisionRequest(BaseModel):
    name: str
    platform: str
    store_url: str
    credentials: dict[str, Any]
    pack_id: str = "kbeauty"
```

And in `provision_tenant`, add `pack_id=body.pack_id` when constructing `Tenant`:
```python
tenant = Tenant(
    name=body.name,
    platform=body.platform,
    store_url=body.store_url,
    credentials_enc=enc,
    pack_id=body.pack_id,
)
```

- [ ] **Step 6: Run tests**

```
cd services/core && python -m pytest tests/test_tenant_pack.py -v
```
Expected: 2 PASS

- [ ] **Step 7: Commit**

```
git add services/core/helix/db/models.py \
        services/core/helix/db/migrations/versions/0002_tenant_pack_id.py \
        services/core/helix/api/routers/tenants.py \
        services/core/tests/test_tenant_pack.py
git commit -m "feat: add pack_id column to tenant (migration 0002)"
```

---

### Task 2 (P3-2): Per-tenant pack routing

**Files:**
- Modify: `services/core/helix/packs/registry.py`
- Modify: `services/core/helix/api/routers/widget.py`
- Modify: `services/core/helix/api/routers/sync.py`
- Modify: `services/core/helix/api/routers/search.py`
- Test: `services/core/tests/test_tenant_pack.py` (add 2 more tests)

**Context:**
- Current `registry.py` exports `default_pack()` (returns first loaded pack) and `get_pack(pack_id)` (raises KeyError if missing)
- `widget.py` calls `default_pack()` in both `widget_chat` and `widget_routine`
- `sync.py` calls `default_pack()` in both `sync_products` and `sync_customers`
- `search.py` does NOT call `default_pack()` — only 4 callers total in widget.py (×2) and sync.py (×2)
- The `Tenant` model now has `pack_id: Optional[str]`

- [ ] **Step 1: Write failing tests**

Append to `services/core/tests/test_tenant_pack.py`:
```python
from helix.packs.registry import get_pack_for_tenant, _registry
from helix.db.models import Tenant
from unittest.mock import MagicMock

def test_get_pack_for_tenant_uses_pack_id():
    mock_pack = MagicMock()
    mock_pack.id = "haircare"
    _registry["haircare"] = mock_pack

    tenant = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
                    credentials_enc=b"enc", pack_id="haircare")
    result = get_pack_for_tenant(tenant)
    assert result is mock_pack

    del _registry["haircare"]

def test_get_pack_for_tenant_falls_back_when_pack_missing():
    mock_pack = MagicMock()
    mock_pack.id = "kbeauty"
    _registry.clear()
    _registry["kbeauty"] = mock_pack

    tenant = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
                    credentials_enc=b"enc", pack_id="unknown_pack")
    result = get_pack_for_tenant(tenant)
    assert result is mock_pack
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_tenant_pack.py::test_get_pack_for_tenant_uses_pack_id tests/test_tenant_pack.py::test_get_pack_for_tenant_falls_back_when_pack_missing -v
```
Expected: `AttributeError: get_pack_for_tenant`

- [ ] **Step 3: Add `get_pack_for_tenant` to registry.py**

In `services/core/helix/packs/registry.py`, add after `default_pack()`:
```python
from helix.db.models import Tenant

def get_pack_for_tenant(tenant: Tenant) -> LoadedPack:
    pack_id = tenant.pack_id or "kbeauty"
    if pack_id in _registry:
        return _registry[pack_id]
    return default_pack()
```

- [ ] **Step 4: Update widget.py callers**

In `services/core/helix/api/routers/widget.py`:
- Change `from helix.packs.registry import default_pack` to `from helix.packs.registry import get_pack_for_tenant`
- In `widget_chat`: replace `pack = default_pack()` with `pack = get_pack_for_tenant(tenant)`
- In `widget_routine`: replace `pack = default_pack()` with `pack = get_pack_for_tenant(tenant)`

- [ ] **Step 5: Update sync.py callers**

In `services/core/helix/api/routers/sync.py`:
- Change `from helix.packs.registry import default_pack` to `from helix.packs.registry import get_pack_for_tenant`
- In `sync_products`: replace `pack = default_pack()` with `pack = get_pack_for_tenant(tenant)`
- In `sync_customers`: replace `pack = default_pack()` with `pack = get_pack_for_tenant(tenant)`

- [ ] **Step 6: Run all tests**

```
cd services/core && python -m pytest tests/test_tenant_pack.py tests/test_chat_endpoint.py tests/test_routine_endpoint.py tests/test_sync_endpoint.py tests/test_customer_sync.py -v
```
Expected: all PASS

- [ ] **Step 7: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 83 PASS (81 + 4 new)

- [ ] **Step 8: Commit**

```
git add services/core/helix/packs/registry.py \
        services/core/helix/api/routers/widget.py \
        services/core/helix/api/routers/sync.py \
        services/core/tests/test_tenant_pack.py
git commit -m "feat: per-tenant domain pack routing via get_pack_for_tenant"
```

---

### Task 3 (P3-3): Tenant management endpoints (GET + PATCH)

**Files:**
- Modify: `services/core/helix/db/crud/tenants.py`
- Modify: `services/core/helix/api/routers/tenants.py`
- Test: `services/core/tests/test_tenant_management.py`

**Context:**
- `get_tenant_by_id(session, tenant_id)` already exists in `crud/tenants.py` — no need to add
- Auth: `X-Helix-Provision-Key` header (same as provisioning) — check against `settings.provision_key.get_secret_value()`
- Both endpoints return `401` for bad provision key, `404` for unknown tenant
- `credentials_enc` must NEVER appear in responses
- `asyncio_mode = "auto"` — no `@pytest.mark.asyncio`
- Use `app.dependency_overrides[get_db] = lambda: mock_db` pattern for test isolation

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_tenant_management.py
from unittest.mock import AsyncMock, MagicMock
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_db
from helix.db.models import Tenant
from tests.conftest import make_test_settings


@pytest.fixture
def tenant():
    t = Tenant(name="Seoul Beauty", platform="woocommerce",
               store_url="https://sb.com", credentials_enc=b"enc", pack_id="kbeauty")
    t.id = uuid4()
    t.public_key = uuid4()
    from datetime import datetime, timezone
    t.created_at = datetime.now(timezone.utc)
    return t


@pytest.fixture
def client(tenant):
    settings = make_test_settings()
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)

    from helix.db.crud.tenants import get_tenant_by_id
    from helix.api import deps
    orig_get_tenant_by_id = deps.get_tenant_by_id if hasattr(deps, "get_tenant_by_id") else None

    async def _get_tenant_mock(session, tenant_id):
        return tenant if str(tenant_id) == str(tenant.id) else None

    import helix.db.crud.tenants as tenants_crud
    tenants_crud.get_tenant_by_id = _get_tenant_mock

    async def _update_tenant_mock(session, t, **fields):
        for k, v in fields.items():
            setattr(t, k, v)
        return t

    tenants_crud.update_tenant = _update_tenant_mock

    app.dependency_overrides[get_db] = lambda: mock_db
    return TestClient(app), tenant, settings


def test_get_tenant_returns_details(client):
    c, tenant, settings = client
    r = c.get(
        f"/v1/tenants/{tenant.id}",
        headers={"X-Helix-Provision-Key": settings.provision_key.get_secret_value()},
    )
    assert r.status_code == 200
    data = r.json()
    assert data["tenant_id"] == str(tenant.id)
    assert data["name"] == "Seoul Beauty"
    assert data["pack_id"] == "kbeauty"
    assert "credentials_enc" not in data


def test_get_tenant_401_bad_key(client):
    c, tenant, _ = client
    r = c.get(f"/v1/tenants/{tenant.id}", headers={"X-Helix-Provision-Key": "wrong"})
    assert r.status_code == 401


def test_get_tenant_404_unknown(client):
    c, _, settings = client
    r = c.get(
        f"/v1/tenants/{uuid4()}",
        headers={"X-Helix-Provision-Key": settings.provision_key.get_secret_value()},
    )
    assert r.status_code == 404


def test_patch_tenant_updates_pack_id(client):
    c, tenant, settings = client
    r = c.patch(
        f"/v1/tenants/{tenant.id}",
        json={"pack_id": "haircare"},
        headers={"X-Helix-Provision-Key": settings.provision_key.get_secret_value()},
    )
    assert r.status_code == 200
    assert r.json()["pack_id"] == "haircare"


def test_patch_tenant_updates_name(client):
    c, tenant, settings = client
    r = c.patch(
        f"/v1/tenants/{tenant.id}",
        json={"name": "New Name"},
        headers={"X-Helix-Provision-Key": settings.provision_key.get_secret_value()},
    )
    assert r.status_code == 200
    assert r.json()["name"] == "New Name"
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_tenant_management.py -v
```
Expected: `404 Not Found` (endpoints don't exist yet)

- [ ] **Step 3: Add `update_tenant` to crud/tenants.py**

Append to `services/core/helix/db/crud/tenants.py`:
```python
async def update_tenant(session: AsyncSession, tenant: Tenant, **fields) -> Tenant:
    for key, value in fields.items():
        setattr(tenant, key, value)
    session.add(tenant)
    await session.flush()
    await session.refresh(tenant)
    return tenant
```

- [ ] **Step 4: Add GET and PATCH endpoints to tenants.py**

Add these imports at the top of `services/core/helix/api/routers/tenants.py`:
```python
from uuid import UUID
from helix.db.crud.tenants import get_tenant_by_id, update_tenant
```

Add the response schema and two new endpoints:
```python
class TenantDetail(BaseModel):
    tenant_id: str
    name: str
    platform: str
    store_url: str
    pack_id: str | None
    public_key: str
    created_at: str


class TenantPatchRequest(BaseModel):
    name: str | None = None
    pack_id: str | None = None


def _auth_provision_key(
    x_helix_provision_key: Annotated[str | None, Header()] = None,
) -> str:
    settings = get_settings()
    if x_helix_provision_key != settings.provision_key.get_secret_value():
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid provision key")
    return x_helix_provision_key


@router.get("/{tenant_id}", response_model=TenantDetail)
async def get_tenant(
    tenant_id: UUID,
    _: str = Depends(_auth_provision_key),
    db: AsyncSession = Depends(get_db),
) -> TenantDetail:
    tenant = await get_tenant_by_id(db, tenant_id)
    if not tenant:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Tenant not found")
    return TenantDetail(
        tenant_id=str(tenant.id),
        name=tenant.name,
        platform=tenant.platform,
        store_url=tenant.store_url,
        pack_id=tenant.pack_id,
        public_key=str(tenant.public_key),
        created_at=tenant.created_at.isoformat(),
    )


@router.patch("/{tenant_id}", response_model=TenantDetail)
async def patch_tenant(
    tenant_id: UUID,
    body: TenantPatchRequest,
    _: str = Depends(_auth_provision_key),
    db: AsyncSession = Depends(get_db),
) -> TenantDetail:
    tenant = await get_tenant_by_id(db, tenant_id)
    if not tenant:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Tenant not found")
    updates = {k: v for k, v in body.model_dump(exclude_unset=True).items() if v is not None}
    if updates:
        tenant = await update_tenant(db, tenant, **updates)
        await db.commit()
    return TenantDetail(
        tenant_id=str(tenant.id),
        name=tenant.name,
        platform=tenant.platform,
        store_url=tenant.store_url,
        pack_id=tenant.pack_id,
        public_key=str(tenant.public_key),
        created_at=tenant.created_at.isoformat(),
    )
```

- [ ] **Step 5: Run tests**

```
cd services/core && python -m pytest tests/test_tenant_management.py -v
```
Expected: 5 PASS

- [ ] **Step 6: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 88 PASS

- [ ] **Step 7: Commit**

```
git add services/core/helix/db/crud/tenants.py \
        services/core/helix/api/routers/tenants.py \
        services/core/tests/test_tenant_management.py
git commit -m "feat: tenant GET and PATCH management endpoints"
```

---

### Task 4 (P3-4): Job status endpoints

**Files:**
- Create: `services/core/helix/db/crud/jobs.py`
- Create: `services/core/helix/api/routers/jobs.py`
- Modify: `services/core/helix/api/app.py`
- Test: `services/core/tests/test_jobs_endpoint.py`

**Context:**
- `Job` model: `id, tenant_id, type, status, progress, total, error, started_at, finished_at, created_at` — defined in `db/models.py`
- Auth: `X-Helix-Tenant-Key` (same as sync/search endpoints) via `get_tenant` dep
- Tenant isolation: all queries must filter by `tenant_id`
- `asyncio_mode = "auto"`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_jobs_endpoint.py
from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_db, get_tenant
from helix.db.models import Job, Tenant
from tests.conftest import make_test_settings


@pytest.fixture
def tenant():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc")
    t.id = uuid4()
    t.public_key = uuid4()
    return t


@pytest.fixture
def job(tenant):
    j = Job(tenant_id=tenant.id, type="product_sync", status="running",
            progress=50, total=200)
    j.id = uuid4()
    j.error = None
    j.started_at = datetime.now(timezone.utc)
    j.finished_at = None
    j.created_at = datetime.now(timezone.utc)
    return j


@pytest.fixture
def client(tenant, job):
    settings = make_test_settings()
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)

    import helix.db.crud.jobs as jobs_crud
    jobs_crud.get_job = AsyncMock(return_value=job)
    jobs_crud.list_jobs = AsyncMock(return_value=[job])

    app.dependency_overrides[get_db] = lambda: mock_db
    app.dependency_overrides[get_tenant] = lambda: tenant
    return TestClient(app), tenant, job, settings


def test_get_job_returns_details(client):
    c, tenant, job, _ = client
    r = c.get(f"/v1/jobs/{job.id}",
              headers={"X-Helix-Tenant-Key": str(tenant.public_key)})
    assert r.status_code == 200
    data = r.json()
    assert data["id"] == str(job.id)
    assert data["status"] == "running"
    assert data["progress"] == 50
    assert data["total"] == 200


def test_get_job_404_when_not_found(client):
    import helix.db.crud.jobs as jobs_crud
    jobs_crud.get_job = AsyncMock(return_value=None)
    c, tenant, _, _ = client
    r = c.get(f"/v1/jobs/{uuid4()}",
              headers={"X-Helix-Tenant-Key": str(tenant.public_key)})
    assert r.status_code == 404


def test_list_jobs_returns_list(client):
    c, tenant, job, _ = client
    r = c.get("/v1/jobs",
              headers={"X-Helix-Tenant-Key": str(tenant.public_key)})
    assert r.status_code == 200
    data = r.json()
    assert isinstance(data, list)
    assert len(data) == 1
    assert data[0]["type"] == "product_sync"


def test_list_jobs_filter_by_type(client):
    c, tenant, job, _ = client
    r = c.get("/v1/jobs?type=product_sync",
              headers={"X-Helix-Tenant-Key": str(tenant.public_key)})
    assert r.status_code == 200
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_jobs_endpoint.py -v
```
Expected: `404 Not Found` (routes don't exist)

- [ ] **Step 3: Create `services/core/helix/db/crud/jobs.py`**

```python
from uuid import UUID

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.models import Job


async def get_job(session: AsyncSession, tenant_id: UUID, job_id: UUID) -> Job | None:
    result = await session.execute(
        select(Job).where(Job.tenant_id == tenant_id, Job.id == job_id)
    )
    return result.scalar_one_or_none()


async def list_jobs(
    session: AsyncSession,
    tenant_id: UUID,
    type: str | None = None,
    limit: int = 50,
) -> list[Job]:
    q = select(Job).where(Job.tenant_id == tenant_id)
    if type is not None:
        q = q.where(Job.type == type)
    q = q.order_by(Job.created_at.desc()).limit(limit)
    result = await session.execute(q)
    return list(result.scalars().all())
```

- [ ] **Step 4: Create `services/core/helix/api/routers/jobs.py`**

```python
from typing import Annotated
from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.db.crud import jobs as jobs_crud
from helix.db.models import Tenant

router = APIRouter(prefix="/v1/jobs", tags=["jobs"])


class JobOut(BaseModel):
    id: str
    type: str
    status: str
    progress: int
    total: int | None
    error: str | None
    started_at: str | None
    finished_at: str | None
    created_at: str


def _job_out(job) -> JobOut:
    return JobOut(
        id=str(job.id),
        type=job.type,
        status=job.status,
        progress=job.progress,
        total=job.total,
        error=job.error,
        started_at=job.started_at.isoformat() if job.started_at else None,
        finished_at=job.finished_at.isoformat() if job.finished_at else None,
        created_at=job.created_at.isoformat(),
    )


@router.get("/{job_id}", response_model=JobOut)
async def get_job(
    job_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> JobOut:
    job = await jobs_crud.get_job(db, tenant.id, job_id)
    if not job:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Job not found")
    return _job_out(job)


@router.get("", response_model=list[JobOut])
async def list_jobs(
    type: Annotated[str | None, Query()] = None,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> list[JobOut]:
    jobs = await jobs_crud.list_jobs(db, tenant.id, type=type)
    return [_job_out(j) for j in jobs]
```

- [ ] **Step 5: Register jobs router in app.py**

In `services/core/helix/api/app.py`, add after the analytics router block:
```python
from helix.api.routers import jobs
app.include_router(jobs.router)
```

- [ ] **Step 6: Run tests**

```
cd services/core && python -m pytest tests/test_jobs_endpoint.py -v
```
Expected: 4 PASS

- [ ] **Step 7: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 92 PASS

- [ ] **Step 8: Commit**

```
git add services/core/helix/db/crud/jobs.py \
        services/core/helix/api/routers/jobs.py \
        services/core/helix/api/app.py \
        services/core/tests/test_jobs_endpoint.py
git commit -m "feat: job status endpoints GET /v1/jobs/{id} and GET /v1/jobs"
```

---

### Task 5 (P3-5): Widget JS embed endpoint

**Files:**
- Modify: `services/core/helix/api/routers/widget.py`
- Test: `services/core/tests/test_widget_embed.py`

**Context:**
- Widget embed is `GET /v1/widget/embed.js?key=<public_key>` — no auth required
- Returns `Content-Type: application/javascript`
- The JS: reads `key` from URL params of the current `<script>` tag's `src`; calls `/v1/widget/session` with `X-Helix-Tenant-Key: <key>` to get JWT; stores token in localStorage; injects a floating button and chat pane; sends messages to `/v1/widget/chat`
- Demo page: `GET /v1/widget/demo.html` — only in development environment; returns `404` in production
- Use FastAPI `Response` with explicit `media_type`
- `asyncio_mode = "auto"`

- [ ] **Step 1: Write failing tests**

```python
# services/core/tests/test_widget_embed.py
from fastapi.testclient import TestClient
from helix.api.app import create_app
from tests.conftest import make_test_settings


def test_embed_js_returns_javascript():
    settings = make_test_settings()
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/widget/embed.js?key=00000000-0000-0000-0000-000000000001")
    assert r.status_code == 200
    assert "javascript" in r.headers["content-type"]
    assert "fetch" in r.text


def test_embed_js_contains_api_calls():
    settings = make_test_settings()
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/widget/embed.js?key=00000000-0000-0000-0000-000000000001")
    assert "/v1/widget/session" in r.text
    assert "/v1/widget/chat" in r.text


def test_demo_html_available_in_development():
    settings = make_test_settings(environment="development")
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/widget/demo.html")
    assert r.status_code == 200
    assert "text/html" in r.headers["content-type"]
    assert "embed.js" in r.text


def test_demo_html_404_in_production():
    settings = make_test_settings(environment="production")
    app = create_app(settings)
    c = TestClient(app)
    r = c.get("/v1/widget/demo.html")
    assert r.status_code == 404
```

- [ ] **Step 2: Run tests to see them fail**

```
cd services/core && python -m pytest tests/test_widget_embed.py -v
```
Expected: 4 FAIL (routes don't exist)

- [ ] **Step 3: Add embed.js and demo.html endpoints to widget.py**

Add these imports at the top of `widget.py`:
```python
from fastapi import Response
from helix.config import get_settings as _get_settings
```

Add the two new endpoints at the bottom of `widget.py`:

```python
_EMBED_JS = r"""
(function () {
  var script = document.currentScript;
  var key = (new URLSearchParams(script && script.src ? new URL(script.src).search : '')).get('key')
    || (script && script.getAttribute('data-helix-key'));

  if (!key) { console.warn('[Helix] No key provided'); return; }

  var LS_TOKEN = 'helix_token_' + key;
  var LS_EXP   = 'helix_token_exp_' + key;
  var base = script && script.src ? new URL(script.src).origin : '';

  function getToken() {
    var exp = parseInt(localStorage.getItem(LS_EXP) || '0', 10);
    if (exp > Date.now()) return Promise.resolve(localStorage.getItem(LS_TOKEN));
    return fetch(base + '/v1/widget/session', {
      method: 'POST',
      headers: { 'X-Helix-Tenant-Key': key, 'Content-Type': 'application/json' },
      body: '{}'
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      localStorage.setItem(LS_TOKEN, d.token);
      localStorage.setItem(LS_EXP, String(Date.now() + (d.expires_in - 60) * 1000));
      return d.token;
    });
  }

  var style = document.createElement('style');
  style.textContent = [
    '#helix-btn{position:fixed;bottom:24px;right:24px;width:52px;height:52px;border-radius:50%;',
    'background:#6c47ff;border:none;cursor:pointer;color:#fff;font-size:22px;box-shadow:0 4px 12px rgba(0,0,0,.25);}',
    '#helix-panel{display:none;position:fixed;bottom:88px;right:24px;width:340px;max-height:480px;',
    'background:#fff;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.15);',
    'display:none;flex-direction:column;overflow:hidden;}',
    '#helix-messages{flex:1;overflow-y:auto;padding:16px;font-family:sans-serif;font-size:14px;}',
    '#helix-msg-row{display:flex;padding:8px;border-top:1px solid #f0f0f0;}',
    '#helix-input{flex:1;border:1px solid #ddd;border-radius:6px;padding:6px 10px;font-size:14px;outline:none;}',
    '#helix-send{margin-left:8px;background:#6c47ff;color:#fff;border:none;border-radius:6px;',
    'padding:6px 12px;cursor:pointer;font-size:14px;}'
  ].join('');
  document.head.appendChild(style);

  var btn = document.createElement('button');
  btn.id = 'helix-btn';
  btn.textContent = '💬';
  document.body.appendChild(btn);

  var panel = document.createElement('div');
  panel.id = 'helix-panel';
  panel.innerHTML = [
    '<div id="helix-messages"></div>',
    '<div id="helix-msg-row">',
    '<input id="helix-input" placeholder="Ask anything..." />',
    '<button id="helix-send">Send</button>',
    '</div>'
  ].join('');
  document.body.appendChild(panel);

  var open = false;
  btn.addEventListener('click', function(){
    open = !open;
    panel.style.display = open ? 'flex' : 'none';
  });

  function addMsg(text, role) {
    var d = document.getElementById('helix-messages');
    var p = document.createElement('p');
    p.style.margin = '4px 0';
    p.style.color = role === 'user' ? '#333' : '#6c47ff';
    p.textContent = (role === 'user' ? 'You: ' : 'Helix: ') + text;
    d.appendChild(p);
    d.scrollTop = d.scrollHeight;
  }

  function send() {
    var input = document.getElementById('helix-input');
    var q = input.value.trim();
    if (!q) return;
    input.value = '';
    addMsg(q, 'user');
    getToken().then(function(token){
      return fetch(base + '/v1/widget/chat', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
        body: JSON.stringify({ query: q, customer_profile: {} })
      });
    })
    .then(function(r){ return r.json(); })
    .then(function(d){ addMsg(d.response || d.detail || 'Error', 'helix'); })
    .catch(function(){ addMsg('Could not reach Helix. Please try again.', 'helix'); });
  }

  document.getElementById('helix-send').addEventListener('click', send);
  document.getElementById('helix-input').addEventListener('keydown', function(e){
    if (e.key === 'Enter') send();
  });
})();
""".strip()


@router.get("/embed.js", include_in_schema=False)
async def widget_embed_js() -> Response:
    return Response(content=_EMBED_JS, media_type="application/javascript")


@router.get("/demo.html", include_in_schema=False)
async def widget_demo_html() -> Response:
    settings = _get_settings()
    if settings.environment != "development":
        from fastapi import HTTPException, status
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Not found")
    html = """<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Helix Widget Demo</title></head>
<body style="font-family:sans-serif;padding:40px;background:#f9f9f9;">
<h1>Helix Widget Demo</h1>
<p>Set your tenant public key in the script src below:</p>
<script src="/v1/widget/embed.js?key=YOUR_PUBLIC_KEY"></script>
</body>
</html>"""
    return Response(content=html, media_type="text/html")
```

- [ ] **Step 4: Run tests**

```
cd services/core && python -m pytest tests/test_widget_embed.py -v
```
Expected: 4 PASS

- [ ] **Step 5: Run full suite**

```
cd services/core && python -m pytest -v
```
Expected: 96 PASS

- [ ] **Step 6: Commit**

```
git add services/core/helix/api/routers/widget.py \
        services/core/tests/test_widget_embed.py
git commit -m "feat: embeddable widget JS (GET /v1/widget/embed.js) + dev demo page"
```

---

### Task 6 (P3-6): Full test suite + PROGRESS.md

**Files:**
- No new implementation code
- Update: `docs/PROGRESS.md`

**Context:**
- Target: ~96 tests passing (81 baseline + 15 new in Phase 3)
- Update status snapshot, add Phase 3 tasks section, add session log entry
- `asyncio_mode = "auto"` in pyproject.toml (never add `@pytest.mark.asyncio`)

- [ ] **Step 1: Run full test suite**

```
cd services/core && python -m pytest -v --tb=short
```
Expected: all tests pass. If any fail, fix before updating PROGRESS.md.

- [ ] **Step 2: Update PROGRESS.md status snapshot**

In `docs/PROGRESS.md`, update the status snapshot section:
```markdown
## Status snapshot
- **Current phase:** Phase 3 — Multi-Pack & Widget Embed
- **Overall:** complete
- **Last updated:** 2026-06-11
- **Last worked by:** Claude Sonnet 4.6
- **Build health:** green — <N>/N tests pass
```

- [ ] **Step 3: Add Phase 3 tasks section and session log entry**

Add `## Phase 3` section listing 6 tasks as checkboxes (all checked).

Add `### 2026-06-11 (Phase 3) — Claude Sonnet 4.6` session log entry summarising what was built.

- [ ] **Step 4: Commit**

```
git add docs/PROGRESS.md
git commit -m "docs: Phase 3 complete — <N> tests pass"
```
