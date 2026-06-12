# Phase 11 — Customer List & Segment Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add merchant-facing customer list/detail endpoints, customer conversation history, and customer segment analytics.

**Architecture:** New `customers.py` router (list + detail + conversations-by-customer); two CRUD additions (`list_customers`, `count_customers`, `get_customer_segments` in `customers.py`; `list_conversations_by_customer` in `conversations.py`); one analytics endpoint added to `analytics.py`; router registered in `app.py`.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy 2.x async, pytest (asyncio_mode=auto)

---

## Context for all tasks

- `asyncio_mode = "auto"` — NEVER add `@pytest.mark.asyncio`
- Patch at namespace where name is USED (`helix.api.routers.customers.X`)
- Tests call `app.dependency_overrides.clear()` after running
- Auth dep is `get_tenant` from `helix.api.deps`; `get_db` is also from there
- `Customer` model fields: `id (UUID)`, `tenant_id (UUID)`, `platform_id (str)`, `email_hash (str)`, `profile (dict JSONB)`, `created_at (datetime)`
- `Conversation` model fields: `id (UUID)`, `tenant_id (UUID)`, `customer_id (UUID|None)`, `created_at (datetime)`, `updated_at (datetime)`
- `list_customers` / `count_customers` / `get_customer_segments` are added to `helix.db.crud.customers`
- `list_conversations_by_customer` is added to `helix.db.crud.conversations`
- `CRUD pattern`: `result.scalars().all()` for list queries (not `.scalars()`)

---

## Task P11-1: Customer list & detail endpoints

**Files:**
- Modify: `services/core/helix/db/crud/customers.py`
- Create: `services/core/helix/api/routers/customers.py`
- Modify: `services/core/helix/api/app.py`
- Create: `services/core/tests/test_customer_list.py`

### Step 1: Add CRUD functions to `customers.py`

Add at the end of `services/core/helix/db/crud/customers.py`:

```python
from sqlalchemy import func


async def list_customers(
    session: AsyncSession,
    tenant_id: UUID,
    limit: int = 20,
    offset: int = 0,
) -> list[Customer]:
    result = await session.execute(
        select(Customer)
        .where(Customer.tenant_id == tenant_id)
        .order_by(Customer.created_at.desc())
        .limit(limit)
        .offset(offset)
    )
    return result.scalars().all()


async def count_customers(
    session: AsyncSession,
    tenant_id: UUID,
) -> int:
    result = await session.execute(
        select(func.count(Customer.id)).where(Customer.tenant_id == tenant_id)
    )
    return result.scalar_one()
```

Note: `select` is already imported; add `func` to the `sqlalchemy` import line if not present.

### Step 2: Create `customers.py` router

Create `services/core/helix/api/routers/customers.py`:

```python
from typing import Annotated
from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.db.crud.customers import count_customers, get_customer_by_id, list_customers
from helix.db.models import Tenant

router = APIRouter(prefix="/v1/customers", tags=["customers"])


class CustomerOut(BaseModel):
    id: str
    platform_id: str
    email_hash: str
    profile: dict
    created_at: str


class CustomerListResponse(BaseModel):
    customers: list[CustomerOut]
    total: int
    limit: int
    offset: int


@router.get("", response_model=CustomerListResponse)
async def list_customers_endpoint(
    limit: Annotated[int, Query(ge=1, le=100)] = 20,
    offset: Annotated[int, Query(ge=0)] = 0,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> CustomerListResponse:
    customers = await list_customers(db, tenant.id, limit=limit, offset=offset)
    total = await count_customers(db, tenant.id)
    return CustomerListResponse(
        customers=[
            CustomerOut(
                id=str(c.id),
                platform_id=c.platform_id,
                email_hash=c.email_hash,
                profile=c.profile or {},
                created_at=c.created_at.isoformat(),
            )
            for c in customers
        ],
        total=total,
        limit=limit,
        offset=offset,
    )


@router.get("/{customer_id}", response_model=CustomerOut)
async def get_customer_endpoint(
    customer_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> CustomerOut:
    customer = await get_customer_by_id(db, customer_id, tenant.id)
    if customer is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Customer not found")
    return CustomerOut(
        id=str(customer.id),
        platform_id=customer.platform_id,
        email_hash=customer.email_hash,
        profile=customer.profile or {},
        created_at=customer.created_at.isoformat(),
    )
```

### Step 3: Register router in `app.py`

In `services/core/helix/api/app.py`, add after the `conversations` router import block:

```python
from helix.api.routers import customers
app.include_router(customers.router)
```

### Step 4: Create `test_customer_list.py`

Create `services/core/tests/test_customer_list.py`:

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Customer, Tenant
from tests.conftest import make_test_settings


def _make_mock_customer():
    from datetime import datetime, timezone
    c = MagicMock(spec=Customer)
    c.id = uuid4()
    c.platform_id = "cust-001"
    c.email_hash = "abc123"
    c.profile = {"skin_type": "oily"}
    c.created_at = datetime(2026, 6, 1, tzinfo=timezone.utc)
    return c


def test_customer_list_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_customers = [_make_mock_customer(), _make_mock_customer()]

    with (
        patch(
            "helix.api.routers.customers.list_customers",
            new_callable=AsyncMock,
            return_value=mock_customers,
        ),
        patch(
            "helix.api.routers.customers.count_customers",
            new_callable=AsyncMock,
            return_value=2,
        ),
    ):
        r = client.get("/v1/customers")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["customers"]) == 2
    assert data["total"] == 2


def test_customer_detail_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_customer = _make_mock_customer()
    customer_id = mock_customer.id

    with patch(
        "helix.api.routers.customers.get_customer_by_id",
        new_callable=AsyncMock,
        return_value=mock_customer,
    ):
        r = client.get(f"/v1/customers/{customer_id}")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["id"] == str(customer_id)


def test_customer_detail_404_on_unknown():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    customer_id = uuid4()

    with patch(
        "helix.api.routers.customers.get_customer_by_id",
        new_callable=AsyncMock,
        return_value=None,
    ):
        r = client.get(f"/v1/customers/{customer_id}")

    app.dependency_overrides.clear()

    assert r.status_code == 404
```

### Step 5: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core" && python -m py_compile helix/db/crud/customers.py helix/api/routers/customers.py helix/api/app.py tests/test_customer_list.py
```

### Step 6: Commit

```bash
git add services/core/helix/db/crud/customers.py services/core/helix/api/routers/customers.py services/core/helix/api/app.py services/core/tests/test_customer_list.py
git commit -m "feat: customer list and detail GET /v1/customers"
```

---

## Task P11-2: Customer conversation history

**Files:**
- Modify: `services/core/helix/db/crud/conversations.py`
- Modify: `services/core/helix/api/routers/customers.py`
- Create: `services/core/tests/test_customer_conversations.py`

### Step 1: Add `list_conversations_by_customer` to `conversations.py` CRUD

Add at the end of `services/core/helix/db/crud/conversations.py`:

```python
async def list_conversations_by_customer(
    session: AsyncSession,
    tenant_id: UUID,
    customer_id: UUID,
    limit: int = 20,
    offset: int = 0,
) -> list[Conversation]:
    result = await session.execute(
        select(Conversation)
        .where(
            Conversation.tenant_id == tenant_id,
            Conversation.customer_id == customer_id,
        )
        .order_by(Conversation.created_at.desc())
        .limit(limit)
        .offset(offset)
    )
    return result.scalars().all()
```

### Step 2: Add endpoint to `customers.py` router

Add to `services/core/helix/api/routers/customers.py`:

Add import at the top:
```python
from helix.db.crud.conversations import list_conversations_by_customer
```

Add model:
```python
class ConversationSummary(BaseModel):
    id: str
    customer_id: str | None
    created_at: str
    updated_at: str


class CustomerConversationsResponse(BaseModel):
    conversations: list[ConversationSummary]
```

Add endpoint (after the `get_customer_endpoint` function):
```python
@router.get("/{customer_id}/conversations", response_model=CustomerConversationsResponse)
async def get_customer_conversations_endpoint(
    customer_id: UUID,
    limit: Annotated[int, Query(ge=1, le=100)] = 20,
    offset: Annotated[int, Query(ge=0)] = 0,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> CustomerConversationsResponse:
    customer = await get_customer_by_id(db, customer_id, tenant.id)
    if customer is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Customer not found")
    convs = await list_conversations_by_customer(
        db, tenant.id, customer_id, limit=limit, offset=offset
    )
    return CustomerConversationsResponse(
        conversations=[
            ConversationSummary(
                id=str(c.id),
                customer_id=str(c.customer_id) if c.customer_id else None,
                created_at=c.created_at.isoformat(),
                updated_at=c.updated_at.isoformat(),
            )
            for c in convs
        ]
    )
```

### Step 3: Create `test_customer_conversations.py`

Create `services/core/tests/test_customer_conversations.py`:

```python
from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Conversation, Customer, Tenant
from tests.conftest import make_test_settings


def _make_mock_conversation(customer_id):
    c = MagicMock(spec=Conversation)
    c.id = uuid4()
    c.customer_id = customer_id
    c.created_at = datetime(2026, 6, 1, tzinfo=timezone.utc)
    c.updated_at = datetime(2026, 6, 1, tzinfo=timezone.utc)
    return c


def test_customer_conversations_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    customer_id = uuid4()
    mock_customer = MagicMock(spec=Customer)
    mock_customer.id = customer_id

    mock_convs = [
        _make_mock_conversation(customer_id),
        _make_mock_conversation(customer_id),
    ]

    with (
        patch(
            "helix.api.routers.customers.get_customer_by_id",
            new_callable=AsyncMock,
            return_value=mock_customer,
        ),
        patch(
            "helix.api.routers.customers.list_conversations_by_customer",
            new_callable=AsyncMock,
            return_value=mock_convs,
        ),
    ):
        r = client.get(f"/v1/customers/{customer_id}/conversations")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert len(r.json()["conversations"]) == 2


def test_customer_conversations_404_on_unknown_customer():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    customer_id = uuid4()

    with patch(
        "helix.api.routers.customers.get_customer_by_id",
        new_callable=AsyncMock,
        return_value=None,
    ):
        r = client.get(f"/v1/customers/{customer_id}/conversations")

    app.dependency_overrides.clear()

    assert r.status_code == 404


def test_customer_conversations_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get(f"/v1/customers/{uuid4()}/conversations")

    assert r.status_code == 401
```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core" && python -m py_compile helix/db/crud/conversations.py helix/api/routers/customers.py tests/test_customer_conversations.py
```

### Step 5: Commit

```bash
git add services/core/helix/db/crud/conversations.py services/core/helix/api/routers/customers.py services/core/tests/test_customer_conversations.py
git commit -m "feat: customer conversation history GET /v1/customers/{id}/conversations"
```

---

## Task P11-3: Customer segment analytics

**Files:**
- Modify: `services/core/helix/db/crud/customers.py`
- Modify: `services/core/helix/api/routers/analytics.py`
- Create: `services/core/tests/test_customer_segments.py`

### Step 1: Add `get_customer_segments` to `customers.py` CRUD

Add at the end of `services/core/helix/db/crud/customers.py` (after `count_customers`):

```python
async def get_customer_segments(
    session: AsyncSession,
    tenant_id: UUID,
) -> list[dict]:
    skin_type_col = func.jsonb_extract_path_text(
        Customer.profile, "skin_type"
    ).label("skin_type")
    result = await session.execute(
        select(skin_type_col, func.count(Customer.id).label("count"))
        .where(Customer.tenant_id == tenant_id)
        .group_by(skin_type_col)
        .order_by(func.count(Customer.id).desc())
    )
    return [
        {"skin_type": row.skin_type or "unknown", "count": row.count}
        for row in result.all()
    ]
```

`func` is already imported from Step 1 of P11-1. `select` is already imported.

### Step 2: Add endpoint to `analytics.py`

Add import at the top of `services/core/helix/api/routers/analytics.py`:

```python
from helix.db.crud.customers import get_customer_segments
```

Add model and endpoint at the end of `analytics.py`:

```python
class CustomerSegmentItem(BaseModel):
    skin_type: str
    count: int


class CustomerSegmentsResponse(BaseModel):
    segments: list[CustomerSegmentItem]


@router.get("/customers/segments", response_model=CustomerSegmentsResponse)
async def get_customer_segments_endpoint(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> CustomerSegmentsResponse:
    segments = await get_customer_segments(db, tenant.id)
    return CustomerSegmentsResponse(
        segments=[CustomerSegmentItem(**s) for s in segments]
    )
```

### Step 3: Create `test_customer_segments.py`

Create `services/core/tests/test_customer_segments.py`:

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def test_customer_segments_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_segments = [
        {"skin_type": "oily", "count": 24},
        {"skin_type": "dry", "count": 18},
    ]

    with patch(
        "helix.api.routers.analytics.get_customer_segments",
        new_callable=AsyncMock,
        return_value=mock_segments,
    ):
        r = client.get("/v1/analytics/customers/segments")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["segments"]) == 2
    assert data["segments"][0]["skin_type"] == "oily"
    assert data["segments"][0]["count"] == 24


def test_customer_segments_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/customers/segments")

    assert r.status_code == 401


def test_customer_segments_empty():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch(
        "helix.api.routers.analytics.get_customer_segments",
        new_callable=AsyncMock,
        return_value=[],
    ):
        r = client.get("/v1/analytics/customers/segments")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["segments"] == []
```

### Step 4: Syntax check

```
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core" && python -m py_compile helix/db/crud/customers.py helix/api/routers/analytics.py tests/test_customer_segments.py
```

### Step 5: Commit

```bash
git add services/core/helix/db/crud/customers.py helix/api/routers/analytics.py services/core/tests/test_customer_segments.py
git commit -m "feat: customer segment analytics GET /v1/analytics/customers/segments"
```

---

## Task P11-4: Full suite + PROGRESS.md

Update `docs/PROGRESS.md`:
- Status: Phase 11 complete, 202/202 tests pass (193 prior + 3 + 3 + 3 = 202)
- Add Phase 11 section and session log entry

```bash
git add docs/PROGRESS.md && git commit -m "docs: Phase 11 complete — 202 tests"
```
