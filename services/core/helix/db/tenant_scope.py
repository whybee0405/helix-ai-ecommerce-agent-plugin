from uuid import UUID

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.models import Product


class TenantScope:
    """Wraps an AsyncSession with a fixed tenant_id. All query methods enforce isolation."""

    def __init__(self, session: AsyncSession, tenant_id: UUID) -> None:
        self._session = session
        self._tenant_id = tenant_id

    async def get_products(self) -> list[Product]:
        result = await self._session.execute(
            select(Product).where(Product.tenant_id == self._tenant_id)
        )
        return list(result.scalars().all())

    async def get_product_by_platform_id(self, platform_id: str) -> Product | None:
        result = await self._session.execute(
            select(Product).where(
                Product.tenant_id == self._tenant_id,
                Product.platform_id == platform_id,
            )
        )
        return result.scalar_one_or_none()
