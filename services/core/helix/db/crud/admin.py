from datetime import datetime

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.models import Customer, Product, Tenant, UsageEvent


async def get_platform_stats(
    session: AsyncSession, month_start: datetime, month_end: datetime
) -> dict:
    """Get platform-wide statistics across all tenants."""
    tenant_count = (await session.execute(select(func.count(Tenant.id)))).scalar_one()
    product_count = (await session.execute(select(func.count(Product.id)))).scalar_one()
    customer_count = (await session.execute(select(func.count(Customer.id)))).scalar_one()

    usage_row = (
        await session.execute(
            select(
                func.count(UsageEvent.id).label("total"),
                func.sum(UsageEvent.cost_usd).label("cost"),
            ).where(UsageEvent.created_at >= month_start, UsageEvent.created_at <= month_end)
        )
    ).one()

    return {
        "total_tenants": tenant_count,
        "total_products": product_count,
        "total_customers": customer_count,
        "queries_this_month": usage_row.total or 0,
        "cost_this_month_usd": round(float(usage_row.cost or 0), 6),
    }
