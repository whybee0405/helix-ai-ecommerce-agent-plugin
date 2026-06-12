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


async def vector_search_products(
    session: AsyncSession,
    tenant_id: UUID,
    query_vector: list[float],
    limit: int = 10,
    in_stock_only: bool = False,
    category: str | None = None,
) -> list[tuple[Product, float]]:
    distance_col = Product.embedding.cosine_distance(query_vector).label("distance")
    filters = [Product.tenant_id == tenant_id, Product.embedding.is_not(None)]
    if in_stock_only:
        filters.append(Product.in_stock.is_(True))
    if category:
        filters.append(Product.categories.contains([category]))
    q = (
        select(Product, distance_col)
        .where(*filters)
        .order_by(distance_col)
        .limit(limit)
    )
    result = await session.execute(q)
    return [(row.Product, 1.0 - row.distance) for row in result]


async def suggest_product_titles(
    session: AsyncSession,
    tenant_id: UUID,
    prefix: str,
    limit: int = 5,
) -> list[str]:
    result = await session.execute(
        select(Product.title)
        .where(
            Product.tenant_id == tenant_id,
            Product.title.ilike(f"{prefix}%"),
        )
        .order_by(Product.title)
        .limit(limit)
    )
    return [row.title for row in result]
