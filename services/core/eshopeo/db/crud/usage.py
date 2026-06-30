from datetime import date, datetime, timezone
from uuid import UUID

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.db.models import UsageEvent


async def record_usage(session: AsyncSession, event: UsageEvent) -> None:
    session.add(event)
    await session.flush()


async def get_usage_summary(
    session: AsyncSession,
    tenant_id: UUID,
    start_date: date,
    end_date: date,
) -> dict:
    start_dt = datetime(start_date.year, start_date.month, start_date.day, tzinfo=timezone.utc)
    end_dt = datetime(end_date.year, end_date.month, end_date.day, 23, 59, 59, tzinfo=timezone.utc)

    rows = await session.execute(
        select(
            UsageEvent.model,
            func.count(UsageEvent.id).label("calls"),
            func.sum(UsageEvent.cost_usd).label("cost_usd"),
        )
        .where(
            UsageEvent.tenant_id == tenant_id,
            UsageEvent.created_at >= start_dt,
            UsageEvent.created_at <= end_dt,
        )
        .group_by(UsageEvent.model)
    )
    by_model = [
        {"model": row.model, "calls": row.calls, "cost_usd": float(row.cost_usd or 0)}
        for row in rows
    ]
    total_calls = sum(m["calls"] for m in by_model)
    total_cost = sum(m["cost_usd"] for m in by_model)

    return {
        "total_queries": total_calls,
        "llm_calls": total_calls,
        "total_cost_usd": round(total_cost, 6),
        "by_model": by_model,
    }
