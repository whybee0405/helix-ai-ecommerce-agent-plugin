from datetime import datetime
from uuid import UUID

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.crud.admin import get_tenant_usage_summary
from helix.db.models import ContentDraft, Conversation, Customer, Product


async def get_dashboard_summary(
    session: AsyncSession,
    tenant_id: UUID,
    month_start: datetime,
    month_end: datetime,
) -> dict:
    product_count = (
        await session.execute(
            select(func.count(Product.id)).where(Product.tenant_id == tenant_id)
        )
    ).scalar_one()

    customer_count = (
        await session.execute(
            select(func.count(Customer.id)).where(Customer.tenant_id == tenant_id)
        )
    ).scalar_one()

    conversations_this_month = (
        await session.execute(
            select(func.count(Conversation.id)).where(
                Conversation.tenant_id == tenant_id,
                Conversation.created_at >= month_start,
                Conversation.created_at < month_end,
            )
        )
    ).scalar_one()

    pending_drafts = (
        await session.execute(
            select(func.count(ContentDraft.id)).where(
                ContentDraft.tenant_id == tenant_id,
                ContentDraft.status == "pending",
            )
        )
    ).scalar_one()

    usage = await get_tenant_usage_summary(session, tenant_id, month_start, month_end)

    return {
        "product_count": product_count,
        "customer_count": customer_count,
        "conversations_this_month": conversations_this_month,
        "pending_drafts": pending_drafts,
        "queries_this_month": usage["total_queries"],
        "cost_this_month_usd": usage["total_cost_usd"],
    }
