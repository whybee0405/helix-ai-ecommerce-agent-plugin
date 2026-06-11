from uuid import UUID

from sqlalchemy import select
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.models import Customer, Order


async def upsert_order(session: AsyncSession, order: Order) -> Order:
    stmt = (
        insert(Order)
        .values(
            id=order.id,
            tenant_id=order.tenant_id,
            platform_id=order.platform_id,
            customer_id=order.customer_id,
            total_minor=order.total_minor,
            currency=order.currency,
            status=order.status,
            line_items=order.line_items,
            placed_at=order.placed_at,
        )
        .on_conflict_do_update(
            constraint="uq_order_tenant_platform",
            set_=dict(
                customer_id=order.customer_id,
                total_minor=order.total_minor,
                currency=order.currency,
                status=order.status,
                line_items=order.line_items,
                placed_at=order.placed_at,
            ),
        )
        .returning(Order)
    )
    result = await session.execute(stmt)
    return result.scalar_one()


async def get_customer_id_by_platform_id(
    session: AsyncSession, tenant_id: UUID, platform_id: str
) -> UUID | None:
    result = await session.execute(
        select(Customer.id).where(
            Customer.tenant_id == tenant_id,
            Customer.platform_id == platform_id,
        )
    )
    return result.scalar_one_or_none()
