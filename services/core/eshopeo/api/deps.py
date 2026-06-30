from collections.abc import AsyncGenerator
from typing import Annotated
from uuid import UUID

from fastapi import Depends, Header, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.auth.tokens import InvalidTokenError, validate_access_token, validate_widget_token
from eshopeo.db.crud.tenants import get_tenant_by_id, get_tenant_by_public_key
from eshopeo.db.engine import async_session_factory
from eshopeo.db.models import Tenant


async def get_db() -> AsyncGenerator[AsyncSession, None]:
    async with async_session_factory() as session:
        yield session


async def get_tenant(
    x_eshopeo_tenant_key: Annotated[str | None, Header()] = None,
    db: AsyncSession = Depends(get_db),
) -> Tenant:
    if x_eshopeo_tenant_key is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Missing tenant key")
    try:
        key_uuid = UUID(x_eshopeo_tenant_key)
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
    from eshopeo.config import get_settings as _gs
    settings = _gs()
    try:
        tenant_id = validate_widget_token(token, settings.session_secret.get_secret_value())
    except InvalidTokenError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid or expired token")
    tenant = await get_tenant_by_id(db, tenant_id)
    if tenant is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Tenant not found")
    return tenant


# ── AI Ops quota dependency ───────────────────────────────────────────────────

_OPS_COUNTER_TTL = 32 * 24 * 3600  # 32 days


async def check_ai_op_quota(
    tenant: Tenant = Depends(get_tenant),
) -> Tenant:
    """Dependency for all WP Agent endpoints. Enforces monthly AI ops limit."""
    from datetime import datetime, timezone

    import redis.asyncio as aioredis
    from fastapi import HTTPException, status

    from eshopeo.billing.plans import get_ai_ops_limit
    from eshopeo.config import get_settings

    if tenant.is_internal:
        return tenant  # internal/dev tenants bypass all ops limits

    limit = get_ai_ops_limit(tenant.tier, tenant.plan_ai_ops_limit)

    if limit == 0:
        raise HTTPException(
            status_code=status.HTTP_402_PAYMENT_REQUIRED,
            detail="WP Agent is not included in your plan. Upgrade to Growth or Pro.",
            headers={"X-eShopeo-Upgrade": "required"},
        )

    if limit == -1:
        return tenant  # unlimited — skip Redis entirely

    s = get_settings()
    month_key = datetime.now(timezone.utc).strftime("%Y-%m")
    counter_key = f"ai_ops:{tenant.id}:{month_key}"

    try:
        r = aioredis.from_url(str(s.redis_url), decode_responses=True)
        count = await r.incr(counter_key)
        if count == 1:
            await r.expire(counter_key, _OPS_COUNTER_TTL)
        if count > limit:
            raise HTTPException(
                status_code=status.HTTP_429_TOO_MANY_REQUESTS,
                detail=f"Monthly AI ops limit ({limit}) reached. Upgrade to Pro for unlimited access.",
                headers={"X-Ops-Exceeded": "monthly"},
            )
    except HTTPException:
        raise
    except Exception:
        pass  # Redis failure → allow through

    return tenant


async def get_dashboard_tenant(
    authorization: Annotated[str | None, Header()] = None,
    db: AsyncSession = Depends(get_db),
) -> Tenant:
    """Dependency for dashboard endpoints — validates a short-lived access JWT."""
    if authorization is None or not authorization.startswith("Bearer "):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Missing bearer token")
    token = authorization.removeprefix("Bearer ")
    from eshopeo.config import get_settings as _gs
    try:
        tenant_id = validate_access_token(token, _gs().session_secret.get_secret_value())
    except InvalidTokenError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid or expired token")
    tenant = await get_tenant_by_id(db, tenant_id)
    if tenant is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Tenant not found")
    return tenant


def _decrypt_api_key(enc: bytes, settings) -> str:
    from cryptography.fernet import Fernet
    f = Fernet(settings.credential_encryption_key.get_secret_value().encode())
    return f.decrypt(enc).decode()


def get_tenant_anthropic_key(tenant: Tenant) -> str | None:
    """Return tenant BYOK key (decrypted) if set, else None (use platform key)."""
    if not tenant.anthropic_api_key_enc:
        return None
    try:
        from eshopeo.config import get_settings
        return _decrypt_api_key(tenant.anthropic_api_key_enc, get_settings())
    except Exception:
        return None
