from collections.abc import AsyncGenerator
from typing import Annotated
from uuid import UUID

from fastapi import Depends, Header, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.crud.tenants import get_tenant_by_public_key
from helix.db.engine import async_session_factory
from helix.db.models import Tenant


async def get_db() -> AsyncGenerator[AsyncSession, None]:
    async with async_session_factory() as session:
        yield session


async def get_tenant(
    x_helix_tenant_key: Annotated[str | None, Header()] = None,
    db: AsyncSession = Depends(get_db),
) -> Tenant:
    if x_helix_tenant_key is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Missing tenant key")
    try:
        key_uuid = UUID(x_helix_tenant_key)
    except ValueError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid tenant key")
    tenant = await get_tenant_by_public_key(db, key_uuid)
    if tenant is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Unknown tenant key")
    return tenant
