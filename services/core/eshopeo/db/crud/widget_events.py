from datetime import datetime, timedelta, timezone
from uuid import UUID, uuid4

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.db.models import WidgetEvent


async def create_widget_event(
    session: AsyncSession,
    tenant_id: UUID,
    event_type: str,
    conversation_id: UUID | None = None,
    metadata: dict | None = None,
) -> WidgetEvent:
    event = WidgetEvent(
        id=uuid4(),
        tenant_id=tenant_id,
        conversation_id=conversation_id,
        event_type=event_type,
        event_data=metadata or {},
    )
    session.add(event)
    await session.commit()
    return event


async def get_widget_event_summary(
    session: AsyncSession,
    tenant_id: UUID,
    start: datetime,
    end: datetime,
) -> list[dict]:
    rows = (
        await session.execute(
            select(WidgetEvent.event_type, func.count(WidgetEvent.id).label("count"))
            .where(
                WidgetEvent.tenant_id == tenant_id,
                WidgetEvent.created_at >= start,
                WidgetEvent.created_at < end,
            )
            .group_by(WidgetEvent.event_type)
            .order_by(func.count(WidgetEvent.id).desc())
        )
    ).all()
    return [{"event_type": r.event_type, "count": r.count} for r in rows]


async def get_daily_event_counts(
    session: AsyncSession,
    tenant_id: UUID,
    days: int = 30,
) -> list[dict]:
    from sqlalchemy import cast, Date as SADate
    cutoff = datetime.now(timezone.utc) - timedelta(days=days)
    rows = (
        await session.execute(
            select(
                cast(WidgetEvent.created_at, SADate).label("day"),
                WidgetEvent.event_type,
                func.count(WidgetEvent.id).label("count"),
            )
            .where(
                WidgetEvent.tenant_id == tenant_id,
                WidgetEvent.created_at >= cutoff,
            )
            .group_by(cast(WidgetEvent.created_at, SADate), WidgetEvent.event_type)
            .order_by(cast(WidgetEvent.created_at, SADate))
        )
    ).all()
    return [{"day": str(r.day), "event_type": r.event_type, "count": r.count} for r in rows]
