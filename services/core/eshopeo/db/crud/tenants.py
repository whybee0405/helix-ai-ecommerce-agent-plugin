from uuid import UUID

from sqlalchemy import select, func
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.db.models import Tenant


async def get_tenant_by_public_key(
    session: AsyncSession, public_key: UUID
) -> Tenant | None:
    result = await session.execute(
        select(Tenant).where(Tenant.public_key == public_key)
    )
    return result.scalar_one_or_none()


async def get_tenant_by_id(session: AsyncSession, tenant_id: UUID) -> Tenant | None:
    result = await session.execute(
        select(Tenant).where(Tenant.id == tenant_id)
    )
    return result.scalar_one_or_none()


async def create_tenant(session: AsyncSession, tenant: Tenant) -> Tenant:
    session.add(tenant)
    await session.flush()
    await session.refresh(tenant)
    return tenant


async def update_tenant(session: AsyncSession, tenant: Tenant, **fields) -> Tenant:
    for key, value in fields.items():
        setattr(tenant, key, value)
    session.add(tenant)
    await session.flush()
    await session.refresh(tenant)
    return tenant


async def list_tenants(
    session: AsyncSession,
    limit: int = 20,
    offset: int = 0,
) -> list[Tenant]:
    result = await session.execute(
        select(Tenant).order_by(Tenant.created_at.desc()).limit(limit).offset(offset)
    )
    return list(result.scalars().all())


async def count_tenants(session: AsyncSession) -> int:
    result = await session.execute(select(func.count(Tenant.id)))
    return result.scalar_one()


async def get_tenant_by_email(session: AsyncSession, email: str) -> Tenant | None:
    result = await session.execute(
        select(Tenant).where(Tenant.billing_email == email.lower())
    )
    return result.scalar_one_or_none()
