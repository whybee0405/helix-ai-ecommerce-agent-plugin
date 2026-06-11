from uuid import UUID

from sqlalchemy import select
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.models import Product


async def upsert_product(session: AsyncSession, product: Product) -> Product:
    stmt = (
        insert(Product)
        .values(
            id=product.id,
            tenant_id=product.tenant_id,
            platform_id=product.platform_id,
            title=product.title,
            description_html=product.description_html,
            price_minor=product.price_minor,
            currency=product.currency,
            images=product.images,
            categories=product.categories,
            in_stock=product.in_stock,
            domain_attributes=product.domain_attributes,
        )
        .on_conflict_do_update(
            constraint="uq_product_tenant_platform",
            set_=dict(
                title=product.title,
                description_html=product.description_html,
                price_minor=product.price_minor,
                currency=product.currency,
                images=product.images,
                categories=product.categories,
                in_stock=product.in_stock,
                domain_attributes=product.domain_attributes,
                updated_at=product.updated_at,
            ),
        )
        .returning(Product)
    )
    result = await session.execute(stmt)
    await session.flush()
    return result.scalar_one()


async def delete_product(
    session: AsyncSession, tenant_id: UUID, platform_id: str
) -> bool:
    result = await session.execute(
        select(Product).where(
            Product.tenant_id == tenant_id,
            Product.platform_id == platform_id,
        )
    )
    product = result.scalar_one_or_none()
    if product is None:
        return False
    await session.delete(product)
    await session.flush()
    return True
