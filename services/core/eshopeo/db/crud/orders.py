from datetime import date, datetime, timedelta, timezone
from uuid import UUID

from sqlalchemy import func, select
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.db.models import Customer, Order


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


async def get_order_analytics(
    session: AsyncSession,
    tenant_id: UUID,
    start: date | None = None,
    end: date | None = None,
) -> dict:
    stmt = select(
        func.count(Order.id).label("total_orders"),
        func.coalesce(func.sum(Order.total_minor), 0).label("total_revenue_minor"),
    ).where(Order.tenant_id == tenant_id)

    if start:
        start_dt = datetime(start.year, start.month, start.day, tzinfo=timezone.utc)
        stmt = stmt.where(Order.placed_at >= start_dt)
    if end:
        end_dt = datetime(end.year, end.month, end.day, tzinfo=timezone.utc) + timedelta(days=1)
        stmt = stmt.where(Order.placed_at < end_dt)

    row = (await session.execute(stmt)).one()
    total = row.total_orders or 0
    revenue = row.total_revenue_minor or 0
    avg = round(revenue / total) if total > 0 else 0
    return {"total_orders": total, "total_revenue_minor": revenue, "avg_order_value_minor": avg}


async def get_orders_by_status(
    session: AsyncSession,
    tenant_id: UUID,
    start: date | None = None,
    end: date | None = None,
) -> list[dict]:
    stmt = (
        select(
            Order.status,
            func.count(Order.id).label("count"),
            func.coalesce(func.sum(Order.total_minor), 0).label("total_revenue_minor"),
        )
        .where(Order.tenant_id == tenant_id)
    )
    if start:
        start_dt = datetime(start.year, start.month, start.day, tzinfo=timezone.utc)
        stmt = stmt.where(Order.placed_at >= start_dt)
    if end:
        end_dt = datetime(end.year, end.month, end.day, tzinfo=timezone.utc) + timedelta(days=1)
        stmt = stmt.where(Order.placed_at < end_dt)
    stmt = stmt.group_by(Order.status).order_by(func.count(Order.id).desc())
    result = await session.execute(stmt)
    return [
        {"status": row.status, "count": row.count, "total_revenue_minor": row.total_revenue_minor}
        for row in result.all()
    ]
