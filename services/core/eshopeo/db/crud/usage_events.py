from uuid import UUID

from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.db.models import UsageEvent


async def create_usage_event(
    session: AsyncSession,
    tenant_id: UUID,
    model: str,
    tokens_in: int,
    tokens_out: int,
    cost_usd: float,
    endpoint: str,
    source: str | None = None,
) -> UsageEvent:
    event = UsageEvent(
        tenant_id=tenant_id,
        model=model,
        tokens_in=tokens_in,
        tokens_out=tokens_out,
        cost_usd=cost_usd,
        endpoint=endpoint,
        source=source,
    )
    session.add(event)
    await session.flush()
    return event
