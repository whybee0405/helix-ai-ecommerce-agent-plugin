from uuid import UUID

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.models import Job


async def get_job(session: AsyncSession, tenant_id: UUID, job_id: UUID) -> Job | None:
    result = await session.execute(
        select(Job).where(Job.tenant_id == tenant_id, Job.id == job_id)
    )
    return result.scalar_one_or_none()


async def list_jobs(
    session: AsyncSession,
    tenant_id: UUID,
    type: str | None = None,
    limit: int = 50,
) -> list[Job]:
    q = select(Job).where(Job.tenant_id == tenant_id)
    if type is not None:
        q = q.where(Job.type == type)
    q = q.order_by(Job.created_at.desc()).limit(limit)
    result = await session.execute(q)
    return list(result.scalars().all())
