import base64
import hashlib
import hmac
import json
import secrets
import time
from typing import Annotated
from uuid import UUID

from fastapi import APIRouter, Depends, Header, HTTPException, Request, status
from fastapi.responses import JSONResponse
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.auth.crypto import decrypt_credentials, encrypt_credentials
from helix.api.deps import get_db, get_tenant
from helix.packs.registry import get_pack_for_tenant
from helix.branding import (
    Branding,
    BrandingUpdate,
    apply_preset_to_tenant,
    get_effective_branding,
    list_presets,
    update_tenant_branding,
)
from helix.config import get_settings
from helix.db.crud.tenants import get_tenant_by_id, get_tenant_by_public_key, update_tenant
from helix.db.models import Tenant


router = APIRouter(tags=["branding"])


# ─── HMAC helpers ────────────────────────────────────────────────────────────

_TIMESTAMP_TOLERANCE_S = 300  # 5 min window


def _ensure_admin_secret(tenant: Tenant) -> tuple[str, bytes]:
    """Read or generate the tenant's admin_secret. Returns (secret, new_credentials_enc).
    new_credentials_enc is None if no change was made."""
    settings = get_settings()
    creds = decrypt_credentials(
        tenant.credentials_enc, settings.credential_encryption_key.get_secret_value()
    )
    secret = creds.get("admin_secret")
    if secret:
        return secret, tenant.credentials_enc
    secret = secrets.token_hex(32)
    creds["admin_secret"] = secret
    enc = encrypt_credentials(creds, settings.credential_encryption_key.get_secret_value())
    return secret, enc


def _verify_admin_hmac(
    body: bytes,
    timestamp: str,
    signature: str,
    secret: str,
) -> bool:
    try:
        ts = int(timestamp)
    except (TypeError, ValueError):
        return False
    if abs(int(time.time()) - ts) > _TIMESTAMP_TOLERANCE_S:
        return False
    payload = f"{ts}.".encode() + body
    expected = base64.b64encode(
        hmac.new(secret.encode(), payload, hashlib.sha256).digest()
    ).decode()
    return hmac.compare_digest(expected, signature)


async def _auth_admin(
    request: Request,
    x_helix_tenant_id: Annotated[str | None, Header()] = None,
    x_helix_timestamp: Annotated[str | None, Header()] = None,
    x_helix_signature: Annotated[str | None, Header()] = None,
    db: AsyncSession = Depends(get_db),
) -> Tenant:
    if not (x_helix_tenant_id and x_helix_timestamp and x_helix_signature):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Missing admin auth headers",
        )
    try:
        tenant_id = UUID(x_helix_tenant_id)
    except ValueError as e:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid tenant id"
        ) from e
    tenant = await get_tenant_by_id(db, tenant_id)
    if tenant is None:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED, detail="Unknown tenant"
        )
    settings = get_settings()
    creds = decrypt_credentials(
        tenant.credentials_enc, settings.credential_encryption_key.get_secret_value()
    )
    secret = creds.get("admin_secret")
    if not secret:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Tenant has no admin secret; bootstrap one first",
        )
    body = await request.body()
    if not _verify_admin_hmac(body, x_helix_timestamp, x_helix_signature, secret):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid signature"
        )
    return tenant


# ─── Public widget endpoint ──────────────────────────────────────────────────


@router.get("/v1/widget/branding")
async def widget_branding(
    request: Request,
    key: str,
    db: AsyncSession = Depends(get_db),
) -> JSONResponse:
    try:
        key_uuid = UUID(key)
    except ValueError as e:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid key"
        ) from e
    tenant = await get_tenant_by_public_key(db, key_uuid)
    if tenant is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND, detail="Unknown tenant"
        )

    branding = await get_effective_branding(tenant)
    etag = f'W/"branding-{tenant.branding_version}"'

    if request.headers.get("if-none-match") == etag:
        return JSONResponse(content=None, status_code=304, headers={"ETag": etag})

    payload = branding.model_dump(mode="json")
    payload["version"] = tenant.branding_version
    pack = get_pack_for_tenant(tenant)
    payload["pack_cta_type"] = pack.cta_type

    return JSONResponse(
        content=payload,
        headers={
            "ETag": etag,
            "Cache-Control": "public, max-age=60, stale-while-revalidate=600",
            "Vary": "Accept-Encoding",
        },
    )


# ─── Admin endpoints (HMAC-signed) ───────────────────────────────────────────


@router.get("/v1/admin/branding")
async def admin_get_branding(
    tenant: Tenant = Depends(_auth_admin),
) -> dict:
    branding = await get_effective_branding(tenant)
    payload = branding.model_dump(mode="json")
    payload["version"] = tenant.branding_version
    return payload


@router.post("/v1/admin/branding")
async def admin_update_branding(
    patch: BrandingUpdate,
    db: AsyncSession = Depends(get_db),
    tenant: Tenant = Depends(_auth_admin),
) -> dict:
    new = await update_tenant_branding(db, tenant, patch)
    await db.commit()
    payload = new.model_dump(mode="json")
    payload["version"] = tenant.branding_version
    return payload


@router.post("/v1/admin/branding/apply-preset")
async def admin_apply_preset(
    body: dict,
    db: AsyncSession = Depends(get_db),
    tenant: Tenant = Depends(_auth_admin),
) -> dict:
    preset_id = (body or {}).get("preset_id", "general")
    new = await apply_preset_to_tenant(db, tenant, preset_id)
    await db.commit()
    try:
        from helix.workers.tasks.faq_warm import warm_tenant_faq
        warm_tenant_faq.delay(str(tenant.id))
    except Exception:  # noqa: BLE001
        pass
    payload = new.model_dump(mode="json")
    payload["version"] = tenant.branding_version
    return payload


@router.get("/v1/admin/presets")
async def admin_list_presets(
    _: Tenant = Depends(_auth_admin),
) -> list[dict]:
    return list_presets()


@router.get("/v1/admin/usage")
async def admin_usage(
    days: int = 30,
    tenant: Tenant = Depends(_auth_admin),
    db: AsyncSession = Depends(get_db),
) -> dict:
    from datetime import datetime, timedelta, timezone
    from decimal import Decimal
    from sqlalchemy import func, select
    import redis.asyncio as aioredis
    from helix.db.models import UsageEvent
    from helix.llm.budget import check_budget

    days = max(1, min(days, 90))
    since = datetime.now(timezone.utc) - timedelta(days=days)
    rows = await db.execute(
        select(
            func.date_trunc("day", UsageEvent.created_at).label("day"),
            func.coalesce(func.sum(UsageEvent.tokens_in), 0).label("tokens_in"),
            func.coalesce(func.sum(UsageEvent.tokens_out), 0).label("tokens_out"),
            func.coalesce(func.sum(UsageEvent.cost_usd), 0).label("cost_usd"),
            func.count().label("calls"),
        )
        .where(UsageEvent.tenant_id == tenant.id)
        .where(UsageEvent.created_at >= since)
        .group_by("day")
        .order_by("day")
    )
    daily = [
        {
            "day": r.day.date().isoformat(),
            "tokens_in": int(r.tokens_in),
            "tokens_out": int(r.tokens_out),
            "cost_usd": float(r.cost_usd),
            "calls": int(r.calls),
        }
        for r in rows
    ]

    settings = get_settings()
    redis = aioredis.from_url(str(settings.redis_url), decode_responses=True)
    try:
        budget = await check_budget(db, redis, tenant.id, tenant.tier, tenant.daily_budget_usd)
    finally:
        await redis.aclose()

    total = sum(d["cost_usd"] for d in daily)
    return {
        "daily": daily,
        "total_cost_usd": round(total, 6),
        "tier": tenant.tier,
        "daily_budget_usd": float(budget.daily_limit),
        "today_spend_usd": float(budget.daily_spend),
        "budget_mode": budget.mode.value,
    }


# ─── Bootstrap endpoint (uses provision_key, generates admin_secret) ─────────


@router.post("/v1/tenants/{tenant_id}/admin-secret")
async def bootstrap_admin_secret(
    tenant_id: UUID,
    x_helix_provision_key: Annotated[str | None, Header()] = None,
    db: AsyncSession = Depends(get_db),
) -> dict:
    settings = get_settings()
    if x_helix_provision_key != settings.provision_key.get_secret_value():
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid provision key"
        )
    tenant = await get_tenant_by_id(db, tenant_id)
    if tenant is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND, detail="Tenant not found"
        )
    secret, new_enc = _ensure_admin_secret(tenant)
    if new_enc is not tenant.credentials_enc:
        await update_tenant(db, tenant, credentials_enc=new_enc)
        await db.commit()
    return {"admin_secret": secret, "tenant_id": str(tenant.id)}
