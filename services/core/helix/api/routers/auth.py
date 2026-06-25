"""
Auth endpoints for self-service registration and dashboard login.

POST /v1/auth/register          — create tenant + issue tokens (14-day trial)
POST /v1/auth/magic-link        — send magic link to registered email
GET  /v1/auth/session?token=xxx — exchange magic link token for JWT pair
POST /v1/auth/refresh           — rotate refresh token, issue new access token
POST /v1/auth/logout            — invalidate refresh token
GET  /v1/auth/me                — return current tenant info
"""
from __future__ import annotations

import hashlib
import secrets
from datetime import datetime, timedelta, timezone
from typing import Literal

import redis.asyncio as aioredis
import structlog
from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, EmailStr, HttpUrl
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.auth.crypto import encrypt_credentials
from helix.api.auth.tokens import issue_access_token
from helix.api.deps import get_dashboard_tenant, get_db
from helix.billing.plans import PLANS
from helix.branding.presets import get_preset
from helix.config import get_settings
from helix.db.crud.tenants import create_tenant, get_tenant_by_email, get_tenant_by_id
from helix.db.models import Tenant

logger = structlog.get_logger(__name__)

router = APIRouter(prefix="/v1/auth", tags=["auth"])

_TRIAL_DAYS = 14
_MAGIC_TTL = 600          # 10 min
_REFRESH_TTL = 30 * 24 * 3600  # 30 days


# ── Helpers ───────────────────────────────────────────────────────────────────

def _get_redis() -> aioredis.Redis:
    return aioredis.from_url(str(get_settings().redis_url), decode_responses=True)


def _refresh_hash(token: str) -> str:
    return hashlib.sha256(token.encode()).hexdigest()


async def _issue_token_pair(tenant_id, secret: str) -> tuple[str, str]:
    """Return (access_token, refresh_token) and persist refresh in Redis."""
    access = issue_access_token(tenant_id, secret)
    refresh = secrets.token_hex(32)
    r = _get_redis()
    await r.setex(f"refresh:{_refresh_hash(refresh)}", _REFRESH_TTL, str(tenant_id))
    return access, refresh


# ── Pydantic models ───────────────────────────────────────────────────────────

class RegisterRequest(BaseModel):
    email: EmailStr
    store_name: str
    store_url: str
    platform: Literal["woocommerce", "shopify"] = "woocommerce"
    pack_id: str = "general"


class RegisterResponse(BaseModel):
    tenant_id: str
    public_key: str
    admin_secret: str
    trial_ends_at: str
    plugin_download_url: str
    access_token: str
    refresh_token: str
    message: str


class MagicLinkRequest(BaseModel):
    email: EmailStr


class TokenPairResponse(BaseModel):
    access_token: str
    refresh_token: str
    token_type: str = "bearer"
    expires_in: int = 900  # 15 min


class RefreshRequest(BaseModel):
    refresh_token: str


class MeResponse(BaseModel):
    tenant_id: str
    name: str
    store_url: str
    billing_email: str | None
    public_key: str
    pack_id: str | None
    tier: str
    subscription_status: str
    trial_ends_at: str | None
    byok_enabled: bool


# ── Endpoints ─────────────────────────────────────────────────────────────────

@router.post("/register", status_code=status.HTTP_201_CREATED, response_model=RegisterResponse)
async def register(
    body: RegisterRequest,
    db: AsyncSession = Depends(get_db),
) -> RegisterResponse:
    """Self-service tenant registration. Creates a 14-day trial on the Starter plan."""
    settings = get_settings()

    from helix.packs.registry import _registry as pack_registry
    if body.pack_id not in pack_registry and body.pack_id not in ("kbeauty", "automotive", "general"):
        body = body.model_copy(update={"pack_id": "general"})

    admin_secret = secrets.token_hex(32)
    enc = encrypt_credentials(
        {"admin_secret": admin_secret},
        settings.credential_encryption_key.get_secret_value(),
    )

    trial_ends = datetime.now(timezone.utc) + timedelta(days=_TRIAL_DAYS)
    trial_plan = PLANS["starter"]
    preset = get_preset("general")

    tenant = Tenant(
        name=body.store_name,
        platform=body.platform,
        store_url=str(body.store_url),
        credentials_enc=enc,
        pack_id=body.pack_id,
        branding=preset.model_dump(mode="json"),
        branding_version=1,
        tier="starter",
        billing_email=body.email.lower(),
        subscription_status="trialing",
        trial_ends_at=trial_ends,
        plan_query_limit=trial_plan.query_limit,
    )
    tenant = await create_tenant(db, tenant)
    await db.commit()

    logger.info(
        "tenant_registered",
        tenant_id=str(tenant.id),
        email=body.email,
        pack_id=body.pack_id,
    )

    access, refresh = await _issue_token_pair(tenant.id, settings.session_secret.get_secret_value())

    try:
        from helix.workers.tasks.faq_warm import warm_tenant_faq
        warm_tenant_faq.delay(str(tenant.id))
    except Exception:
        pass

    try:
        from helix.notifications.email import send_welcome_email
        send_welcome_email.delay(
            email=body.email,
            store_name=body.store_name,
            trial_ends_at=trial_ends.isoformat(),
            admin_secret=admin_secret,
            public_key=str(tenant.public_key),
        )
    except Exception:
        pass

    plugin_url = f"{settings.api_base_url.rstrip('/')}/v1/plugin/download"

    return RegisterResponse(
        tenant_id=str(tenant.id),
        public_key=str(tenant.public_key),
        admin_secret=admin_secret,
        trial_ends_at=trial_ends.isoformat(),
        plugin_download_url=plugin_url,
        access_token=access,
        refresh_token=refresh,
        message=f"Welcome! Your {_TRIAL_DAYS}-day trial starts now.",
    )


@router.post("/magic-link", status_code=status.HTTP_204_NO_CONTENT)
async def request_magic_link(
    body: MagicLinkRequest,
    db: AsyncSession = Depends(get_db),
) -> None:
    """Send a magic login link. Always returns 204 to prevent email enumeration."""
    tenant = await get_tenant_by_email(db, body.email)
    if tenant is None:
        return  # silent — don't reveal whether email exists

    token = secrets.token_urlsafe(32)
    r = _get_redis()
    await r.setex(f"magic:{token}", _MAGIC_TTL, str(tenant.id))

    settings = get_settings()
    link = f"{settings.app_base_url.rstrip('/')}/verify?token={token}"

    try:
        from helix.notifications.email import send_magic_link_email
        send_magic_link_email.delay(
            email=body.email,
            store_name=tenant.name,
            magic_link=link,
        )
    except Exception:
        logger.warning("magic_link_email_failed", email=body.email)


@router.get("/session", response_model=TokenPairResponse)
async def exchange_magic_link(
    token: str = Query(...),
    db: AsyncSession = Depends(get_db),
) -> TokenPairResponse:
    """Exchange a single-use magic link token for an access + refresh token pair."""
    r = _get_redis()
    key = f"magic:{token}"
    tenant_id_str = await r.get(key)
    if not tenant_id_str:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid or expired login link")

    await r.delete(key)  # single-use

    from uuid import UUID
    tenant = await get_tenant_by_id(db, UUID(tenant_id_str))
    if tenant is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Tenant not found")

    settings = get_settings()
    access, refresh = await _issue_token_pair(tenant.id, settings.session_secret.get_secret_value())

    logger.info("magic_link_used", tenant_id=tenant_id_str)
    return TokenPairResponse(access_token=access, refresh_token=refresh)


@router.post("/refresh", response_model=TokenPairResponse)
async def refresh_token(body: RefreshRequest) -> TokenPairResponse:
    """Rotate refresh token and issue a new access token."""
    r = _get_redis()
    hash_key = f"refresh:{_refresh_hash(body.refresh_token)}"
    tenant_id_str = await r.get(hash_key)
    if not tenant_id_str:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid or expired refresh token")

    # Rotate: delete old, issue new
    await r.delete(hash_key)

    settings = get_settings()
    from uuid import UUID
    new_access = issue_access_token(UUID(tenant_id_str), settings.session_secret.get_secret_value())
    new_refresh = secrets.token_hex(32)
    await r.setex(f"refresh:{_refresh_hash(new_refresh)}", _REFRESH_TTL, tenant_id_str)

    return TokenPairResponse(access_token=new_access, refresh_token=new_refresh)


@router.post("/logout", status_code=status.HTTP_204_NO_CONTENT)
async def logout(body: RefreshRequest) -> None:
    """Invalidate a refresh token."""
    r = _get_redis()
    await r.delete(f"refresh:{_refresh_hash(body.refresh_token)}")


@router.get("/me", response_model=MeResponse)
async def me(tenant: Tenant = Depends(get_dashboard_tenant)) -> MeResponse:
    """Return current tenant info for the dashboard."""
    return MeResponse(
        tenant_id=str(tenant.id),
        name=tenant.name,
        store_url=tenant.store_url,
        billing_email=tenant.billing_email,
        public_key=str(tenant.public_key),
        pack_id=tenant.pack_id,
        tier=tenant.tier,
        subscription_status=tenant.subscription_status,
        trial_ends_at=tenant.trial_ends_at.isoformat() if tenant.trial_ends_at else None,
        byok_enabled=bool(tenant.anthropic_api_key_enc),
    )
