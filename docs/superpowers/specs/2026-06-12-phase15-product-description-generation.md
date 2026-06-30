# Phase 15 — AI Product Description Generation Design Spec

**Date:** 2026-06-12
**Status:** Approved
**Scope:** Merchants can trigger AI-generated product descriptions for individual or all products. Generated drafts are stored for merchant review; approved drafts overwrite the product's `description_html`. Mirrors the embedding pipeline pattern (Celery task + async trigger endpoint).
**Definition of done:** A merchant can trigger bulk description generation, poll for the draft status on individual products, approve a draft to persist it, and see which products still have no AI-generated description.

---

## 1. Gap analysis from Phase 14

| Gap | Impact |
|-----|--------|
| Products have no AI-generated descriptions | Merchants writing copy manually; SEO quality inconsistent |
| No content approval workflow | Generated content needs a human gate before it goes live |
| No bulk content generation trigger | Merchants must generate one product at a time |

**Already done:** LLM gateway (`eshopeo.llm`) supports `generate` tier (Sonnet). Embedding pipeline pattern (`embed_product` Celery task + `POST /v1/jobs/embed/bulk`) is the exact model to follow. Domain pack has product schema + prompt fragments.

---

## 2. Data model — `ContentDraft` (P15-1)

New SQLAlchemy model in `eshopeo/db/models.py`:

```python
class ContentDraft(Base):
    __tablename__ = "content_draft"

    id: Mapped[UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid4)
    tenant_id: Mapped[UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("tenant.id"), nullable=False)
    product_id: Mapped[UUID] = mapped_column(UUID(as_uuid=True), ForeignKey("product.id"), nullable=False)
    field: Mapped[str] = mapped_column(String(64), nullable=False)  # "description_html"
    draft_text: Mapped[str] = mapped_column(Text, nullable=False)
    status: Mapped[str] = mapped_column(String(32), nullable=False, default="pending")  # pending | approved | rejected
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    approved_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    __table_args__ = (
        UniqueConstraint("tenant_id", "product_id", "field", name="uq_content_draft_tenant_product_field"),
    )
```

Alembic migration `0004_content_draft.py`:
```python
op.create_table(
    "content_draft",
    sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
    sa.Column("tenant_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("tenant.id"), nullable=False),
    sa.Column("product_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("product.id"), nullable=False),
    sa.Column("field", sa.String(64), nullable=False),
    sa.Column("draft_text", sa.Text, nullable=False),
    sa.Column("status", sa.String(32), nullable=False, server_default="pending"),
    sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now()),
    sa.Column("approved_at", sa.DateTime(timezone=True), nullable=True),
    sa.UniqueConstraint("tenant_id", "product_id", "field", name="uq_content_draft_tenant_product_field"),
)
op.create_index("ix_content_draft_tenant_id", "content_draft", ["tenant_id"])
op.create_index("ix_content_draft_product_id", "content_draft", ["product_id"])
```

The `UniqueConstraint` on `(tenant_id, product_id, field)` means each product has one draft per field at a time — upsert (delete + insert) on re-generation.

### New CRUD in `eshopeo/db/crud/content.py`

```python
async def upsert_content_draft(
    session: AsyncSession, tenant_id: UUID, product_id: UUID, field: str, draft_text: str
) -> ContentDraft:
    # Delete existing draft for this (tenant_id, product_id, field), then insert new one
    await session.execute(
        delete(ContentDraft).where(
            ContentDraft.tenant_id == tenant_id,
            ContentDraft.product_id == product_id,
            ContentDraft.field == field,
        )
    )
    draft = ContentDraft(
        tenant_id=tenant_id, product_id=product_id, field=field, draft_text=draft_text
    )
    session.add(draft)
    await session.flush()
    await session.refresh(draft)
    return draft


async def get_content_draft(
    session: AsyncSession, tenant_id: UUID, product_id: UUID, field: str = "description_html"
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


async def count_products_without_draft(
    session: AsyncSession, tenant_id: UUID
) -> int:
    # Products that have no ContentDraft at all for field="description_html"
    subq = select(ContentDraft.product_id).where(
        ContentDraft.tenant_id == tenant_id,
        ContentDraft.field == "description_html",
    ).scalar_subquery()
    result = await session.execute(
        select(func.count(Product.id)).where(
            Product.tenant_id == tenant_id,
            Product.id.not_in(subq),
        )
    )
    return result.scalar_one()
```

---

## 3. Celery task — `generate_description` (P15-2)

New task in `eshopeo/workers/tasks/content.py`:

```python
@celery_app.task(name="eshopeo.generate_description")
def generate_description(tenant_id_str: str, product_id_str: str) -> None:
    """Generate a product description draft using the LLM gateway."""
    import asyncio
    asyncio.run(_generate_description_async(UUID(tenant_id_str), UUID(product_id_str)))


async def _generate_description_async(tenant_id: UUID, product_id: UUID) -> None:
    from eshopeo.db.engine import async_session_factory
    from eshopeo.db.crud.products import get_product_by_id
    from eshopeo.db.crud.tenants import get_tenant_by_id
    from eshopeo.db.crud.content import upsert_content_draft
    from eshopeo.packs.loader import get_pack_for_tenant
    from eshopeo.llm.gateway import complete

    async with async_session_factory() as session:
        tenant = await get_tenant_by_id(session, tenant_id)
        product = await get_product_by_id(session, tenant_id, product_id)
        if not tenant or not product:
            return

        pack = get_pack_for_tenant(tenant)
        prompt = _build_description_prompt(product, pack)
        result = await complete(session, tenant, "generate", prompt)
        if result.response:
            await upsert_content_draft(
                session, tenant_id, product_id, "description_html", result.response
            )
            await session.commit()
```

The prompt builder extracts `title`, `categories`, `domain_attributes`, and `price_minor` from the product and uses the pack's product schema to know which attributes are meaningful.

```python
def _build_description_prompt(product, pack) -> str:
    attrs = product.domain_attributes or {}
    attr_lines = "\n".join(f"- {k}: {v}" for k, v in attrs.items() if v)
    return (
        f"Write a compelling, SEO-optimised HTML product description for the following item.\n\n"
        f"Product: {product.title}\n"
        f"Price: {product.price_minor / 100:.2f} {product.currency}\n"
        f"Categories: {', '.join(product.categories or [])}\n"
        f"Attributes:\n{attr_lines}\n\n"
        f"Return only the HTML description body (no <html>/<body> wrappers). "
        f"2-4 short paragraphs, include key attributes naturally in prose, avoid generic filler."
    )
```

### New CRUD needed: `get_product_by_id`

Add to `eshopeo/db/crud/products.py`:
```python
async def get_product_by_id(
    session: AsyncSession, tenant_id: UUID, product_id: UUID
) -> Product | None:
    result = await session.execute(
        select(Product).where(Product.tenant_id == tenant_id, Product.id == product_id)
    )
    return result.scalar_one_or_none()
```

---

## 4. Content endpoints (P15-3)

New router `eshopeo/api/routers/content.py`, prefix `/v1/content`, registered in `app.py`.

### `POST /v1/content/products/{product_id}/generate`

Triggers description generation for one product. Fires `generate_description.delay(...)` and returns 202.

```python
class GenerateResponse(BaseModel):
    product_id: str
    queued: bool

@router.post("/products/{product_id}/generate", response_model=GenerateResponse, status_code=202)
async def generate_product_description(
    product_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> GenerateResponse:
    product = await get_product_by_id(db, tenant.id, product_id)
    if product is None:
        raise HTTPException(status_code=404, detail="Product not found")
    generate_description.delay(str(tenant.id), str(product_id))
    return GenerateResponse(product_id=str(product_id), queued=True)
```

### `GET /v1/content/products/{product_id}/draft`

Returns the current draft for a product (pending or approved).

```python
class ContentDraftOut(BaseModel):
    product_id: str
    field: str
    draft_text: str
    status: str
    created_at: str
    approved_at: str | None

@router.get("/products/{product_id}/draft", response_model=ContentDraftOut)
async def get_product_draft(
    product_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ContentDraftOut:
    draft = await get_content_draft(db, tenant.id, product_id)
    if draft is None:
        raise HTTPException(status_code=404, detail="No draft found for this product")
    return ContentDraftOut(
        product_id=str(draft.product_id),
        field=draft.field,
        draft_text=draft.draft_text,
        status=draft.status,
        created_at=draft.created_at.isoformat(),
        approved_at=draft.approved_at.isoformat() if draft.approved_at else None,
    )
```

### `POST /v1/content/products/{product_id}/draft/approve`

Approves a pending draft and writes `draft_text` → `Product.description_html`.

```python
@router.post("/products/{product_id}/draft/approve", response_model=ContentDraftOut)
async def approve_product_draft(
    product_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ContentDraftOut:
    draft = await get_content_draft(db, tenant.id, product_id)
    if draft is None:
        raise HTTPException(status_code=404, detail="No draft found for this product")
    if draft.status == "approved":
        raise HTTPException(status_code=409, detail="Draft already approved")
    # Write description back to product
    product = await get_product_by_id(db, tenant.id, product_id)
    product.description_html = draft.draft_text
    session.add(product)  # session comes from Depends(get_db)
    await approve_content_draft(db, draft)
    await db.commit()
    return ContentDraftOut(...)
```

**Note:** The endpoint above uses `db` as the session. The `product.description_html = draft.draft_text` and `approve_content_draft` both use the same session, committed once at the end.

### `POST /v1/content/bulk-generate`

Queues description generation for all products without a draft. Mirrors `POST /v1/jobs/embed/bulk`.

```python
class BulkGenerateResponse(BaseModel):
    queued: int

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

New CRUD needed: `list_products_without_draft` in `content.py`:
```python
async def list_products_without_draft(
    session: AsyncSession, tenant_id: UUID
) -> list[Product]:
    subq = select(ContentDraft.product_id).where(
        ContentDraft.tenant_id == tenant_id,
        ContentDraft.field == "description_html",
    ).scalar_subquery()
    result = await session.execute(
        select(Product).where(
            Product.tenant_id == tenant_id,
            Product.id.not_in(subq),
        )
    )
    return list(result.scalars().all())
```

---

## 5. File map

**New files:**
- `services/core/eshopeo/db/crud/content.py` — `upsert_content_draft`, `get_content_draft`, `approve_content_draft`, `count_products_without_draft`, `list_products_without_draft`
- `services/core/eshopeo/workers/tasks/content.py` — `generate_description` Celery task + `_generate_description_async` + `_build_description_prompt`
- `services/core/eshopeo/api/routers/content.py` — 4 endpoints
- `services/core/alembic/versions/0004_content_draft.py` — migration
- `services/core/tests/test_content_draft_crud.py` — CRUD tests (3 tests)
- `services/core/tests/test_content_generate.py` — endpoint tests (3 tests)
- `services/core/tests/test_content_approve.py` — approval + write-back tests (3 tests)
- `services/core/tests/test_content_bulk.py` — bulk trigger tests (3 tests)

**Modified files:**
- `services/core/eshopeo/db/models.py` — add `ContentDraft` model
- `services/core/eshopeo/db/crud/products.py` — add `get_product_by_id`
- `services/core/eshopeo/api/app.py` — `app.include_router(content.router)`

---

## 6. Security constraints

- All content CRUD scoped by `tenant_id`
- `approve_product_draft` uses the same DB session for both draft approval and product write-back (atomic)
- Celery task receives only UUIDs (no tenant secrets in the message payload)
- `draft_text` is HTML — returned as-is, not sanitized at API layer (merchant is the author; sanitize on render in the widget/dashboard)

---

## 7. Task breakdown

| Task | Description | Tests |
|------|-------------|-------|
| P15-1 | `ContentDraft` model + migration 0004 + CRUD (`content.py`) + `get_product_by_id` in products CRUD | 3 |
| P15-2 | `generate_description` Celery task in `workers/tasks/content.py` | 3 |
| P15-3 | Content router: `POST /generate`, `GET /draft`, `POST /draft/approve`, `POST /bulk-generate` + register in `app.py` | 3 |
| P15-4 | Full suite + PROGRESS.md | — |
