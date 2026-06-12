from uuid import UUID

from sqlalchemy import case, func, select
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
    min_price: int | None = None,
    max_price: int | None = None,
) -> list[tuple[Product, float]]:
    distance_col = Product.embedding.cosine_distance(query_vector).label("distance")
    filters = [Product.tenant_id == tenant_id, Product.embedding.is_not(None)]
    if in_stock_only:
        filters.append(Product.in_stock.is_(True))
    if category:
        filters.append(Product.categories.contains([category]))
    if min_price is not None:
        filters.append(Product.price_minor >= min_price)
    if max_price is not None:
        filters.append(Product.price_minor <= max_price)
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


async def get_similar_products(
    session: AsyncSession,
    tenant_id: UUID,
    product_id: UUID,
    limit: int = 5,
) -> list[tuple[Product, float]]:
    source = await session.scalar(
        select(Product).where(
            Product.id == product_id,
            Product.tenant_id == tenant_id,
        )
    )
    if source is None or source.embedding is None:
        return []

    distance_col = Product.embedding.cosine_distance(source.embedding).label("distance")
    result = await session.execute(
        select(Product, distance_col)
        .where(
            Product.tenant_id == tenant_id,
            Product.embedding.is_not(None),
            Product.id != product_id,
        )
        .order_by(distance_col)
        .limit(limit)
    )
    return [(row.Product, 1.0 - row.distance) for row in result]


async def get_embedding_coverage(
    session: AsyncSession,
    tenant_id: UUID,
) -> dict:
    result = await session.execute(
        select(
            func.count(Product.id).label("total"),
            func.count(Product.embedding).label("embedded"),
        ).where(Product.tenant_id == tenant_id)
    )
    row = result.one()
    total = row.total or 0
    embedded = row.embedded or 0
    missing = total - embedded
    coverage_rate = round(embedded / total, 2) if total > 0 else 1.0
    return {
        "total": total,
        "embedded": embedded,
        "missing": missing,
        "coverage_rate": coverage_rate,
    }


async def get_inventory_snapshot(
    session: AsyncSession,
    tenant_id: UUID,
) -> dict:
    result = await session.execute(
        select(
            func.count(Product.id).label("total"),
            func.count(case((Product.in_stock == True, 1))).label("in_stock"),
            func.count(case((Product.in_stock == False, 1))).label("out_of_stock"),
        ).where(Product.tenant_id == tenant_id)
    )
    row = result.one()
    total = row.total or 0
    in_stock = row.in_stock or 0
    out_of_stock = row.out_of_stock or 0
    in_stock_rate = round(in_stock / total, 2) if total > 0 else 1.0
    return {
        "total": total,
        "in_stock": in_stock,
        "out_of_stock": out_of_stock,
        "in_stock_rate": in_stock_rate,
    }


async def browse_products(
    session: AsyncSession,
    tenant_id: UUID,
    in_stock_only: bool = False,
    category: str | None = None,
    min_price: int | None = None,
    max_price: int | None = None,
    limit: int = 20,
    offset: int = 0,
) -> tuple[list[Product], int]:
    filters = [Product.tenant_id == tenant_id]
    if in_stock_only:
        filters.append(Product.in_stock.is_(True))
    if category:
        filters.append(Product.categories.contains([category]))
    if min_price is not None:
        filters.append(Product.price_minor >= min_price)
    if max_price is not None:
        filters.append(Product.price_minor <= max_price)

    count_result = await session.execute(
        select(func.count(Product.id)).where(*filters)
    )
    total = count_result.scalar_one()

    result = await session.execute(
        select(Product)
        .where(*filters)
        .order_by(Product.price_minor.asc())
        .limit(limit)
        .offset(offset)
    )
    return list(result.scalars().all()), total
