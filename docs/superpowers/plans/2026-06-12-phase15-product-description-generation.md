# Phase 15 — AI Product Description Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Merchants can trigger AI description generation per product or in bulk; drafts are stored for review; approving a draft writes `description_html` back to the product.

**Architecture:** New `ContentDraft` model + migration 0004; new `eshopeo/db/crud/content.py` CRUD; Celery task in `eshopeo/workers/tasks/content.py` (sync, mirrors embedding task); new `eshopeo/api/routers/content.py` (4 endpoints); `get_product_by_id` added to products CRUD.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy 2.x async (API layer) + sync (Celery task), Celery, Anthropic Claude (`generate` tier = Sonnet), pytest (asyncio_mode=auto)

---

## Context for all tasks

- `asyncio_mode = "auto"` — NEVER add `@pytest.mark.asyncio`
- Tenant auth: `get_tenant` dependency (not provision key)
- All CRUD scoped by `tenant_id`
- Celery tasks: synchronous using `get_sync_session()` — NOT async — mirrors `embedding.py` pattern
- LLM Gateway: `LLMGateway(settings, tenant_id).complete(ModelTier.GENERATE, system, user, schema, max_tokens=N)`
- Gateway returns an instance of the Pydantic `response_schema` type — use `DescriptionDraft(html: str)` model
- Migration dir: `eshopeo/db/migrations/versions/` — filename `0004_content_draft.py`
- Migration chain: `down_revision = "0003"`
- `ContentDraft` status values: `"pending"` (initial), `"approved"`, `"rejected"`

---

## Task P15-1: ContentDraft model + migration + CRUD + `get_product_by_id`

**Files:**
- Modify: `eshopeo/db/models.py`
- Create: `eshopeo/db/migrations/versions/0004_content_draft.py`
- Create: `eshopeo/db/crud/content.py`
- Modify: `eshopeo/db/crud/products.py`
- Create: `tests/test_content_draft_crud.py`

### Step 1: Add `ContentDraft` to `eshopeo/db/models.py`

Read the file. Add `DateTime` to the sqlalchemy imports (it's not there yet; `TIMESTAMP` is used instead — check). The model uses `TIMESTAMP(timezone=True)` to match the existing pattern. Add to imports if needed.

Append this class at the end of `models.py`:

```python
class ContentDraft(Base):
    __tablename__ = "content_draft"
    __table_args__ = (
        UniqueConstraint("tenant_id", "product_id", "field", name="uq_content_draft_tenant_product_field"),
    )

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    tenant_id: Mapped[UUID] = mapped_column(
        ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False, index=True
    )
    product_id: Mapped[UUID] = mapped_column(
        ForeignKey("product.id", ondelete="CASCADE"), nullable=False, index=True
    )
    field: Mapped[str] = mapped_column(String(64), nullable=False)
    draft_text: Mapped[str] = mapped_column(Text, nullable=False)
    status: Mapped[str] = mapped_column(String(32), nullable=False, default="pending")
    created_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now
    )
    approved_at: Mapped[Optional[datetime]] = mapped_column(
        TIMESTAMP(timezone=True), nullable=True
    )
```

### Step 2: Create migration `eshopeo/db/migrations/versions/0004_content_draft.py`

```python
"""create content_draft table

Revision ID: 0004
Revises: 0003
Create Date: 2026-06-12
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

revision: str = "0004"
down_revision: Union[str, None] = "0003"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "content_draft",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("tenant_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False),
        sa.Column("product_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("product.id", ondelete="CASCADE"), nullable=False),
        sa.Column("field", sa.String(64), nullable=False),
        sa.Column("draft_text", sa.Text, nullable=False),
        sa.Column("status", sa.String(32), nullable=False, server_default="pending"),
        sa.Column("created_at", sa.TIMESTAMP(timezone=True), nullable=False),
        sa.Column("approved_at", sa.TIMESTAMP(timezone=True), nullable=True),
        sa.UniqueConstraint("tenant_id", "product_id", "field", name="uq_content_draft_tenant_product_field"),
    )
    op.create_index("ix_content_draft_tenant_id", "content_draft", ["tenant_id"])
    op.create_index("ix_content_draft_product_id", "content_draft", ["product_id"])


def downgrade() -> None:
    op.drop_index("ix_content_draft_product_id", table_name="content_draft")
    op.drop_index("ix_content_draft_tenant_id", table_name="content_draft")
    op.drop_table("content_draft")
```

### Step 3: Create `eshopeo/db/crud/content.py`

```python
from datetime import datetime, timezone
from uuid import UUID

from sqlalchemy import delete, func, select
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.db.models import ContentDraft, Product


async def upsert_content_draft(
    session: AsyncSession,
    tenant_id: UUID,
    product_id: UUID,
    field: str,
    draft_text: str,
) -> ContentDraft:
    await session.execute(
        delete(ContentDraft).where(
            ContentDraft.tenant_id == tenant_id,
            ContentDraft.product_id == product_id,
            ContentDraft.field == field,
        )
    )
    draft = ContentDraft(
        tenant_id=tenant_id,
        product_id=product_id,
        field=field,
        draft_text=draft_text,
    )
    session.add(draft)
    await session.flush()
    await session.refresh(draft)
    return draft


async def get_content_draft(
    session: AsyncSession,
    tenant_id: UUID,
    product_id: UUID,
    field: str = "description_html",
) -> ContentDraft | None:
    result = await session.execute(
        select(ContentDraft).where(
            ContentDraft.tenant_id == tenant_id,
            ContentDraft.product_id == product_id,
            ContentDraft.field == field,
        )
    )
    return result.scalar_one_or_none()


async def approve_content_draft(
    session: AsyncSession, draft: ContentDraft
) -> ContentDraft:
    draft.status = "approved"
    draft.approved_at = datetime.now(timezone.utc)
    session.add(draft)
    await session.flush()
    await session.refresh(draft)
    return draft


async def list_products_without_draft(
    session: AsyncSession, tenant_id: UUID
) -> list[Product]:
    subq = (
        select(ContentDraft.product_id)
        .where(
            ContentDraft.tenant_id == tenant_id,
            ContentDraft.field == "description_html",
        )
        .scalar_subquery()
    )
    result = await session.execute(
        select(Product).where(
            Product.tenant_id == tenant_id,
            Product.id.not_in(subq),
        )
    )
    return list(result.scalars().all())
```

### Step 4: Add `get_product_by_id` to `eshopeo/db/crud/products.py`

Read the file. Append at the end:

```python
async def get_product_by_id(
    session: AsyncSession, tenant_id: UUID, product_id: UUID
) -> Product | None:
    result = await session.execute(
        select(Product).where(
            Product.tenant_id == tenant_id,
            Product.id == product_id,
        )
    )
    return result.scalar_one_or_none()
```

### Step 5: Create `tests/test_content_draft_crud.py`

```python
from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

import pytest

from eshopeo.db.crud.content import (
    approve_content_draft,
    get_content_draft,
    list_products_without_draft,
    upsert_content_draft,
)
from eshopeo.db.models import ContentDraft, Product


async def test_upsert_content_draft_creates_new():
    session = AsyncMock()
    session.execute = AsyncMock()
    session.flush = AsyncMock()
    session.refresh = AsyncMock()

    tenant_id = uuid4()
    product_id = uuid4()

    draft = await upsert_content_draft(session, tenant_id, product_id, "description_html", "<p>Draft</p>")

    session.add.assert_called_once()
    session.flush.assert_called_once()
    session.refresh.assert_called_once()


async def test_approve_content_draft_sets_status():
    session = AsyncMock()
    session.flush = AsyncMock()
    session.refresh = AsyncMock()

    draft = MagicMock(spec=ContentDraft)
    draft.status = "pending"
    draft.approved_at = None

    await approve_content_draft(session, draft)

    assert draft.status == "approved"
    assert draft.approved_at is not None
    session.add.assert_called_once_with(draft)


async def test_get_content_draft_returns_none_when_missing():
    mock_result = MagicMock()
    mock_result.scalar_one_or_none.return_value = None

    session = AsyncMock()
    session.execute = AsyncMock(return_value=mock_result)

    result = await get_content_draft(session, uuid4(), uuid4())
    assert result is None
```

### Step 6: Syntax check

```powershell
cd "D:/Dev Projects/ai-ecommerce-master-plugin-beauty/services/core"
python -m py_compile eshopeo/db/models.py eshopeo/db/crud/content.py eshopeo/db/crud/products.py eshopeo/db/migrations/versions/0004_content_draft.py tests/test_content_draft_crud.py
```

### Step 7: Run tests

```powershell
python -m pytest tests/test_content_draft_crud.py -v
```

All 3 must pass.

### Step 8: Commit

```powershell
git add eshopeo/db/models.py eshopeo/db/crud/content.py eshopeo/db/crud/products.py eshopeo/db/migrations/versions/0004_content_draft.py tests/test_content_draft_crud.py
git commit -m @'
feat: ContentDraft model, migration 0004, content CRUD, get_product_by_id
'@
```

---

## Task P15-2: `generate_description` Celery task

**Files:**
- Create: `eshopeo/workers/tasks/content.py`
- Create: `tests/test_content_generation_task.py`

### Context: How the gateway works

`LLMGateway` is in `eshopeo.llm.gateway`. It takes `settings: Settings, tenant_id: UUID`. The `complete()` method signature:

```python
async def complete(
    self,
    tier: ModelTier,
    system: str,
    user: str,
    response_schema: Type[T],
    *,
    max_tokens: int = 1024,
    message_history: list[dict] | None = None,
) -> T:
```

It returns an instance of `response_schema` (a Pydantic model). For description generation, define:
```python
class DescriptionDraft(BaseModel):
    html: str
```

The gateway uses structured JSON output, so the LLM returns `{"html": "<p>...</p>"}`.

### Celery task pattern

The existing `embed_product` task uses synchronous code (`get_sync_session()`) — NOT async. The description generation task follows the same pattern. It runs the async LLM gateway call inside `asyncio.run()`:

```python
@celery_app.task(bind=True, max_retries=3, default_retry_delay=60, name="eshopeo.workers.tasks.content.generate_description")
def generate_description(self, tenant_id_str: str, product_id_str: str) -> None:
    try:
        import asyncio
        asyncio.run(_generate_async(tenant_id_str, product_id_str))
    except Exception as exc:
        raise self.retry(exc=exc)
```

### Step 1: Create `eshopeo/workers/tasks/content.py`

```python
import asyncio
import structlog
from uuid import UUID

from pydantic import BaseModel

from eshopeo.workers.celery_app import celery_app

logger = structlog.get_logger(__name__)


class DescriptionDraft(BaseModel):
    html: str


def _build_system_prompt(pack) -> str:
    return (
        "You are a product copywriter for an e-commerce store. "
        "Write compelling, SEO-optimised product descriptions grounded in the product data provided. "
        "Do not invent claims not supported by the product attributes. "
        "Return valid JSON only."
    )


def _build_user_prompt(product) -> str:
    attrs = product.domain_attributes or {}
    attr_lines = "\n".join(f"- {k}: {v}" for k, v in attrs.items() if v)
    price = product.price_minor / 100
    cats = ", ".join(product.categories or [])
    return (
        f"Write an HTML product description for:\n\n"
        f"Title: {product.title}\n"
        f"Price: {price:.2f} {product.currency}\n"
        f"Categories: {cats}\n"
        f"Attributes:\n{attr_lines}\n\n"
        f"2-4 short paragraphs. Return JSON with a single 'html' key containing the HTML body (no <html>/<body> wrappers)."
    )


async def _generate_async(tenant_id_str: str, product_id_str: str) -> None:
    from eshopeo.config import get_settings
    from eshopeo.db.engine import async_session_factory
    from eshopeo.db.crud.products import get_product_by_id
    from eshopeo.db.crud.tenants import get_tenant_by_id
    from eshopeo.db.crud.content import upsert_content_draft
    from eshopeo.packs.loader import get_pack_for_tenant
    from eshopeo.llm.gateway import LLMGateway, ModelTier

    tenant_id = UUID(tenant_id_str)
    product_id = UUID(product_id_str)
    settings = get_settings()

    async with async_session_factory() as session:
        tenant = await get_tenant_by_id(session, tenant_id)
        product = await get_product_by_id(session, tenant_id, product_id)
        if not tenant or not product:
            logger.warning("generate_description_not_found", tenant_id=tenant_id_str, product_id=product_id_str)
            return

        pack = get_pack_for_tenant(tenant)
        gateway = LLMGateway(settings, tenant_id)
        result = await gateway.complete(
            ModelTier.GENERATE,
            _build_system_prompt(pack),
            _build_user_prompt(product),
            DescriptionDraft,
            max_tokens=2048,
        )

        await upsert_content_draft(
            session, tenant_id, product_id, "description_html", result.html
        )
        await session.commit()
        logger.info("generate_description_done", product_id=product_id_str)


@celery_app.task(
    bind=True,
    max_retries=3,
    default_retry_delay=60,
    name="eshopeo.workers.tasks.content.generate_description",
)
def generate_description(self, tenant_id_str: str, product_id_str: str) -> None:
    try:
        asyncio.run(_generate_async(tenant_id_str, product_id_str))
    except Exception as exc:
        raise self.retry(exc=exc)
```

### Step 2: Create `tests/test_content_generation_task.py`

The task calls `asyncio.run(_generate_async(...))`. Test `_generate_async` directly (since it's the async core). Mock the LLM gateway and CRUD calls.

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

import pytest

from eshopeo.workers.tasks.content import _generate_async, _build_user_prompt, DescriptionDraft


async def test_generate_async_upserts_draft():
    tenant_id = uuid4()
    product_id = uuid4()

    mock_tenant = MagicMock()
    mock_tenant.id = tenant_id
    mock_tenant.pack_id = "kbeauty"

    mock_product = MagicMock()
    mock_product.id = product_id
    mock_product.title = "Hydrating Toner"
    mock_product.price_minor = 2500
    mock_product.currency = "USD"
    mock_product.categories = ["toner"]
    mock_product.domain_attributes = {"skin_type": "dry"}

    mock_draft = DescriptionDraft(html="<p>Great toner</p>")

    with (
        patch("eshopeo.workers.tasks.content.get_tenant_by_id", new_callable=AsyncMock, return_value=mock_tenant),
        patch("eshopeo.workers.tasks.content.get_product_by_id", new_callable=AsyncMock, return_value=mock_product),
        patch("eshopeo.workers.tasks.content.upsert_content_draft", new_callable=AsyncMock) as mock_upsert,
        patch("eshopeo.workers.tasks.content.get_pack_for_tenant", return_value=MagicMock()),
        patch("eshopeo.workers.tasks.content.LLMGateway") as mock_gw_cls,
        patch("eshopeo.workers.tasks.content.async_session_factory") as mock_factory,
        patch("eshopeo.workers.tasks.content.get_settings", return_value=MagicMock()),
    ):
        mock_gw = AsyncMock()
        mock_gw.complete = AsyncMock(return_value=mock_draft)
        mock_gw_cls.return_value = mock_gw

        mock_session = AsyncMock()
        mock_session.commit = AsyncMock()
        mock_factory.return_value.__aenter__ = AsyncMock(return_value=mock_session)
        mock_factory.return_value.__aexit__ = AsyncMock(return_value=False)

        await _generate_async(str(tenant_id), str(product_id))

    mock_upsert.assert_called_once_with(
        mock_session, tenant_id, product_id, "description_html", "<p>Great toner</p>"
    )
    mock_session.commit.assert_called_once()


async def test_generate_async_skips_when_product_not_found():
    tenant_id = uuid4()
    product_id = uuid4()

    with (
        patch("eshopeo.workers.tasks.content.get_tenant_by_id", new_callable=AsyncMock, return_value=MagicMock()),
        patch("eshopeo.workers.tasks.content.get_product_by_id", new_callable=AsyncMock, return_value=None),
        patch("eshopeo.workers.tasks.content.upsert_content_draft", new_callable=AsyncMock) as mock_upsert,
        patch("eshopeo.workers.tasks.content.async_session_factory") as mock_factory,
        patch("eshopeo.workers.tasks.content.get_settings", return_value=MagicMock()),
    ):
        mock_session = AsyncMock()
        mock_factory.return_value.__aenter__ = AsyncMock(return_value=mock_session)
        mock_factory.return_value.__aexit__ = AsyncMock(return_value=False)

        await _generate_async(str(tenant_id), str(product_id))

    mock_upsert.assert_not_called()


def test_build_user_prompt_includes_title():
    product = MagicMock()
    product.title = "Vitamin C Serum"
    product.price_minor = 3500
    product.currency = "USD"
    product.categories = ["serum"]
    product.domain_attributes = {"ingredients": "ascorbic acid"}

    prompt = _build_user_prompt(product)
    assert "Vitamin C Serum" in prompt
    assert "ascorbic acid" in prompt
```

### Step 3: Syntax check

```powershell
python -m py_compile eshopeo/workers/tasks/content.py tests/test_content_generation_task.py
```

### Step 4: Run tests

```powershell
python -m pytest tests/test_content_generation_task.py -v
```

All 3 must pass.

### Step 5: Commit

```powershell
git add eshopeo/workers/tasks/content.py tests/test_content_generation_task.py
git commit -m @'
feat: generate_description Celery task with LLM gateway integration
'@
```

---

## Task P15-3: Content router — 4 endpoints

**Files:**
- Create: `eshopeo/api/routers/content.py`
- Modify: `eshopeo/api/app.py`
- Create: `tests/test_content_generate_endpoint.py`
- Create: `tests/test_content_approve_endpoint.py`
- Create: `tests/test_content_bulk_endpoint.py`

### Step 1: Create `eshopeo/api/routers/content.py`

```python
from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db, get_tenant
from eshopeo.db.crud.content import (
    approve_content_draft,
    get_content_draft,
    list_products_without_draft,
    upsert_content_draft,
)
from eshopeo.db.crud.products import get_product_by_id
from eshopeo.db.models import Tenant
from eshopeo.workers.tasks.content import generate_description

router = APIRouter(prefix="/v1/content", tags=["content"])


class GenerateResponse(BaseModel):
    product_id: str
    queued: bool


class ContentDraftOut(BaseModel):
    product_id: str
    field: str
    draft_text: str
    status: str
    created_at: str
    approved_at: str | None


class BulkGenerateResponse(BaseModel):
    queued: int


def _draft_out(draft) -> ContentDraftOut:
    return ContentDraftOut(
        product_id=str(draft.product_id),
        field=draft.field,
        draft_text=draft.draft_text,
        status=draft.status,
        created_at=draft.created_at.isoformat(),
        approved_at=draft.approved_at.isoformat() if draft.approved_at else None,
    )


@router.post("/products/{product_id}/generate", response_model=GenerateResponse, status_code=202)
async def generate_product_description(
    product_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> GenerateResponse:
    product = await get_product_by_id(db, tenant.id, product_id)
    if product is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Product not found")
    generate_description.delay(str(tenant.id), str(product_id))
    return GenerateResponse(product_id=str(product_id), queued=True)


@router.get("/products/{product_id}/draft", response_model=ContentDraftOut)
async def get_product_draft(
    product_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ContentDraftOut:
    draft = await get_content_draft(db, tenant.id, product_id)
    if draft is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="No draft found for this product")
    return _draft_out(draft)


@router.post("/products/{product_id}/draft/approve", response_model=ContentDraftOut)
async def approve_product_draft(
    product_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ContentDraftOut:
    draft = await get_content_draft(db, tenant.id, product_id)
    if draft is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="No draft found for this product")
    if draft.status == "approved":
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Draft already approved")
    product = await get_product_by_id(db, tenant.id, product_id)
    product.description_html = draft.draft_text
    db.add(product)
    draft = await approve_content_draft(db, draft)
    await db.commit()
    return _draft_out(draft)


@router.post("/bulk-generate", response_model=BulkGenerateResponse)
async def bulk_generate_endpoint(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> BulkGenerateResponse:
    products = await list_products_without_draft(db, tenant.id)
    for product in products:
        generate_description.delay(str(tenant.id), str(product.id))
    return BulkGenerateResponse(queued=len(products))
```

**Route ordering note:** `POST /products/{product_id}/draft/approve` and `GET /products/{product_id}/draft` share the `{product_id}` path param — `approve` is a POST while `draft` is a GET, so no ordering conflict. `/bulk-generate` does not conflict with any product path.

### Step 2: Register router in `eshopeo/api/app.py`

Read the file. After the `customers` router block, add:

```python
    from eshopeo.api.routers import content
    app.include_router(content.router)
```

### Step 3: Create `tests/test_content_generate_endpoint.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Product, Tenant
from tests.conftest import make_test_settings


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    t.public_key = uuid4()
    return t


def _make_product(tenant_id):
    p = MagicMock(spec=Product)
    p.id = uuid4()
    p.tenant_id = tenant_id
    return p


def test_generate_returns_202():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product = _make_product(tenant.id)
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_task = MagicMock()
    with (
        patch("eshopeo.api.routers.content.get_product_by_id", new_callable=AsyncMock, return_value=product),
        patch("eshopeo.api.routers.content.generate_description", mock_task),
    ):
        r = client.post(f"/v1/content/products/{product.id}/generate")

    app.dependency_overrides.clear()

    assert r.status_code == 202
    assert r.json()["queued"] is True
    mock_task.delay.assert_called_once_with(str(tenant.id), str(product.id))


def test_generate_404_on_unknown_product():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("eshopeo.api.routers.content.get_product_by_id", new_callable=AsyncMock, return_value=None):
        r = client.post(f"/v1/content/products/{uuid4()}/generate")

    app.dependency_overrides.clear()
    assert r.status_code == 404


def test_generate_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.post(f"/v1/content/products/{uuid4()}/generate")
    assert r.status_code == 401
```

### Step 4: Create `tests/test_content_approve_endpoint.py`

```python
from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import ContentDraft, Product, Tenant
from tests.conftest import make_test_settings


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    return t


def _make_draft(tenant_id, product_id, status="pending"):
    d = MagicMock(spec=ContentDraft)
    d.product_id = product_id
    d.tenant_id = tenant_id
    d.field = "description_html"
    d.draft_text = "<p>Generated</p>"
    d.status = status
    d.created_at = datetime(2026, 6, 12, tzinfo=timezone.utc)
    d.approved_at = None
    return d


def test_approve_draft_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product_id = uuid4()
    draft = _make_draft(tenant.id, product_id)
    approved_draft = _make_draft(tenant.id, product_id, status="approved")
    approved_draft.approved_at = datetime(2026, 6, 12, 1, 0, tzinfo=timezone.utc)

    mock_product = MagicMock(spec=Product)
    mock_product.description_html = None

    app.dependency_overrides[get_tenant] = lambda: tenant

    with (
        patch("eshopeo.api.routers.content.get_content_draft", new_callable=AsyncMock, return_value=draft),
        patch("eshopeo.api.routers.content.get_product_by_id", new_callable=AsyncMock, return_value=mock_product),
        patch("eshopeo.api.routers.content.approve_content_draft", new_callable=AsyncMock, return_value=approved_draft),
    ):
        r = client.post(f"/v1/content/products/{product_id}/draft/approve")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["status"] == "approved"


def test_approve_draft_409_if_already_approved():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product_id = uuid4()
    draft = _make_draft(tenant.id, product_id, status="approved")

    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("eshopeo.api.routers.content.get_content_draft", new_callable=AsyncMock, return_value=draft):
        r = client.post(f"/v1/content/products/{product_id}/draft/approve")

    app.dependency_overrides.clear()
    assert r.status_code == 409


def test_approve_draft_404_no_draft():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("eshopeo.api.routers.content.get_content_draft", new_callable=AsyncMock, return_value=None):
        r = client.post(f"/v1/content/products/{uuid4()}/draft/approve")

    app.dependency_overrides.clear()
    assert r.status_code == 404
```

### Step 5: Create `tests/test_content_bulk_endpoint.py`

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Product, Tenant
from tests.conftest import make_test_settings


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    return t


def _make_product():
    p = MagicMock(spec=Product)
    p.id = uuid4()
    return p


def test_bulk_generate_queues_products():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    products = [_make_product(), _make_product(), _make_product()]
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_task = MagicMock()
    with (
        patch("eshopeo.api.routers.content.list_products_without_draft", new_callable=AsyncMock, return_value=products),
        patch("eshopeo.api.routers.content.generate_description", mock_task),
    ):
        r = client.post("/v1/content/bulk-generate")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["queued"] == 3
    assert mock_task.delay.call_count == 3


def test_bulk_generate_returns_zero_when_all_have_drafts():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("eshopeo.api.routers.content.list_products_without_draft", new_callable=AsyncMock, return_value=[]):
        r = client.post("/v1/content/bulk-generate")

    app.dependency_overrides.clear()
    assert r.status_code == 200
    assert r.json()["queued"] == 0


def test_bulk_generate_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.post("/v1/content/bulk-generate")
    assert r.status_code == 401
```

### Step 6: Syntax check

```powershell
python -m py_compile eshopeo/api/routers/content.py eshopeo/api/app.py tests/test_content_generate_endpoint.py tests/test_content_approve_endpoint.py tests/test_content_bulk_endpoint.py
```

### Step 7: Run tests

```powershell
python -m pytest tests/test_content_generate_endpoint.py tests/test_content_approve_endpoint.py tests/test_content_bulk_endpoint.py -v
```

All 9 must pass.

### Step 8: Commit

```powershell
git add eshopeo/api/routers/content.py eshopeo/api/app.py tests/test_content_generate_endpoint.py tests/test_content_approve_endpoint.py tests/test_content_bulk_endpoint.py
git commit -m @'
feat: content router — generate, draft, approve, bulk-generate endpoints
'@
```

---

## Task P15-4: Full suite + PROGRESS.md

Run the full suite:
```powershell
python -m pytest --tb=no -q
```

Expected: 241 total tests (229 prior + 3 CRUD + 3 task + 9 endpoint = 241), 224 passing (212 prior + 12 new).

Update `docs/PROGRESS.md`:
- Status: Phase 15 complete
- Add Phase 15 section
- Add session log entry

```powershell
git add ../../docs/PROGRESS.md
git commit -m @'
docs: Phase 15 complete — 241 tests
'@
```
