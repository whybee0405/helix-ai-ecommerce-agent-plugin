from datetime import datetime, timezone
from uuid import UUID

from sqlalchemy import delete, select
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.models import ContentDraft, Product


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
