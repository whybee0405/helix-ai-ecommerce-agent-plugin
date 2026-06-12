from collections.abc import AsyncGenerator
from typing import Annotated
from uuid import UUID

from fastapi import Depends, Header, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.auth.tokens import InvalidTokenError, validate_widget_token
from helix.db.crud.tenants import get_tenant_by_id, get_tenant_by_public_key
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


async def get_widget_tenant(
    authorization: Annotated[str | None, Header()] = None,
    db: AsyncSession = Depends(get_db),
) -> Tenant:
    if authorization is None or not authorization.startswith("Bearer "):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Missing bearer token")
    token = authorization.removeprefix("Bearer ")
    from helix.config import get_settings as _gs
    settings = _gs()
    try:
        tenant_id = validate_widget_token(token, settings.session_secret.get_secret_value())
    except InvalidTokenError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid or expired token")
    tenant = await get_tenant_by_id(db, tenant_id)
    if tenant is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Tenant not found")
    return tenant
