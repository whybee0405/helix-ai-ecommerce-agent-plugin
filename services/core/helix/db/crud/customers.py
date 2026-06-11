from uuid import UUID, uuid4

from sqlalchemy import select
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.models import Customer


async def get_customer_by_id(
    session: AsyncSession,
    customer_id: UUID,
    tenant_id: UUID,
) -> Customer | None:
    result = await session.execute(
        select(Customer).where(
            Customer.id == customer_id,
            Customer.tenant_id == tenant_id,
        )
    )
    return result.scalar_one_or_none()


async def upsert_customer(session: AsyncSession, customer: Customer) -> Customer:
    stmt = (
        insert(Customer)
        .values(
            id=customer.id,
            tenant_id=customer.tenant_id,
            platform_id=customer.platform_id,
            email_hash=customer.email_hash,
            profile=customer.profile,
        )
        .on_conflict_do_update(
            constraint="uq_customer_tenant_platform",
            set_=dict(
                email_hash=customer.email_hash,
                profile=customer.profile,
            ),
        )
        .returning(Customer)
    )
    result = await session.execute(stmt)
    await session.flush()
    return result.scalar_one()
