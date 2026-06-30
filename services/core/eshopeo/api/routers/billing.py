"""
Paddle billing router.

POST /v1/billing/checkout   — create Paddle checkout → returns URL
POST /v1/billing/webhook    — receive + handle Paddle events
GET  /v1/billing/status     — current subscription info for a tenant
POST /v1/billing/portal     — generate Paddle customer portal URL
POST /v1/billing/cancel     — cancel subscription at period end
"""
from __future__ import annotations

import structlog
from fastapi import APIRouter, Depends, Header, HTTPException, Request, status
from pydantic import BaseModel
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db, get_tenant
from eshopeo.billing.paddle import PaddleClient, parse_event, verify_webhook
from eshopeo.billing.plans import PLANS, get_plan, get_ai_ops_limit, get_query_limit, plan_from_paddle_price
from eshopeo.config import get_settings
from eshopeo.db.crud.tenants import get_tenant_by_id, update_tenant
from eshopeo.db.models import Tenant

logger = structlog.get_logger(__name__)

router = APIRouter(prefix="/v1/billing", tags=["billing"])


# ── Pydantic models ───────────────────────────────────────────────────────────

class CheckoutRequest(BaseModel):
    tier: str  # starter | growth | pro
    currency: str = "USD"  # USD | ZAR


class CheckoutResponse(BaseModel):
    checkout_url: str


class BillingStatusResponse(BaseModel):
    tier: str
    subscription_status: str
    trial_ends_at: str | None
    days_remaining: int | None
    plan_display_name: str
    query_limit: int
    paddle_subscription_id: str | None
    ai_ops_used: int
    ai_ops_limit: int  # -1 = unlimited
    byok_enabled: bool


class PortalResponse(BaseModel):
    portal_url: str


# ── Helpers ───────────────────────────────────────────────────────────────────

def _get_paddle_client() -> PaddleClient:
    s = get_settings()
    return PaddleClient(
        api_key=s.paddle_api_key.get_secret_value(),
        sandbox=s.paddle_sandbox,
    )


def _resolve_price_id(tier: str, currency: str) -> str:
    s = get_settings()
    key = f"paddle_price_{tier}_{currency.lower()}"
    price_id = getattr(s, key, None)
    if not price_id:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"No Paddle price configured for tier={tier} currency={currency}",
        )
    return price_id


def _invalidate_quota_cache(tenant_id: str) -> None:
    """Fire-and-forget Redis cache bust after subscription change."""
    import asyncio
    try:
        import redis as sync_redis
        s = get_settings()
        r = sync_redis.from_url(str(s.redis_url))
        r.delete(
            f"quota_limit:{tenant_id}",
            f"quota_status:{tenant_id}",
        )
    except Exception:
        pass


# ── Endpoints ─────────────────────────────────────────────────────────────────

@router.post("/checkout", response_model=CheckoutResponse)
async def create_checkout(
    body: CheckoutRequest,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> CheckoutResponse:
    """Generate a Paddle hosted checkout URL for a tenant."""
    if body.tier not in ("starter", "growth", "pro"):
        raise HTTPException(status_code=400, detail="Invalid tier")

    price_id = _resolve_price_id(body.tier, body.currency)
    s = get_settings()
    paddle = _get_paddle_client()

    result = await paddle.create_checkout(
        price_id=price_id,
        customer_email=tenant.billing_email or "",
        tenant_id=str(tenant.id),
        success_url=f"{s.app_base_url}/billing/success",
        cancel_url=f"{s.app_base_url}/billing/cancel",
    )
    logger.info("checkout_created", tenant_id=str(tenant.id), tier=body.tier)
    return CheckoutResponse(checkout_url=result["checkout_url"])


@router.post("/webhook", include_in_schema=False)
async def paddle_webhook(
    request: Request,
    db: AsyncSession = Depends(get_db),
    paddle_signature: str | None = Header(None, alias="Paddle-Signature"),
) -> dict:
    """Receive and process Paddle webhook events."""
    raw_body = await request.body()
    s = get_settings()

    if not verify_webhook(raw_body, paddle_signature or "", s.paddle_webhook_secret.get_secret_value()):
        raise HTTPException(status_code=401, detail="Invalid webhook signature")

    event = parse_event(raw_body)
    event_type: str = event.get("event_type", "")
    data: dict = event.get("data", {})

    logger.info("paddle_webhook", event_type=event_type)

    await _handle_event(event_type, data, db)
    return {"received": True}


async def _handle_event(event_type: str, data: dict, db: AsyncSession) -> None:
    tenant_id = _extract_tenant_id(data)
    if not tenant_id:
        logger.warning("paddle_webhook_no_tenant", event_type=event_type)
        return

    from uuid import UUID
    tenant = await get_tenant_by_id(db, UUID(tenant_id))
    if not tenant:
        logger.warning("paddle_webhook_tenant_not_found", tenant_id=tenant_id)
        return

    if event_type == "subscription.created":
        await _on_subscription_created(tenant, data, db)
    elif event_type == "subscription.updated":
        await _on_subscription_updated(tenant, data, db)
    elif event_type == "subscription.cancelled":
        await _on_subscription_cancelled(tenant, data, db)
    elif event_type in ("subscription.payment_failed", "subscription.past_due"):
        await _on_payment_failed(tenant, data, db)
    elif event_type == "subscription.resumed":
        await _on_subscription_resumed(tenant, data, db)
    elif event_type == "transaction.completed":
        logger.info("paddle_transaction_completed", tenant_id=tenant_id)

    _invalidate_quota_cache(tenant_id)


def _extract_tenant_id(data: dict) -> str | None:
    """Paddle stores our metadata in custom_data."""
    custom = data.get("custom_data") or {}
    if isinstance(custom, dict):
        return custom.get("tenant_id")
    return None


async def _on_subscription_created(tenant: Tenant, data: dict, db: AsyncSession) -> None:
    price_id = _get_price_id_from_sub(data)
    plan = plan_from_paddle_price(price_id) if price_id else None
    tier = plan.tier if plan else "starter"

    await update_tenant(db, tenant,
        tier=tier,
        subscription_status="active",
        trial_ends_at=None,
        paddle_subscription_id=data.get("id"),
        paddle_customer_id=data.get("customer_id"),
        plan_query_limit=PLANS[tier].query_limit,
        plan_ai_ops_limit=PLANS[tier].ai_ops_limit,
    )
    await db.commit()
    logger.info("subscription_activated", tenant_id=str(tenant.id), tier=tier)

    try:
        from eshopeo.notifications.email import send_subscription_confirmed
        send_subscription_confirmed.delay(
            email=tenant.billing_email or "",
            store_name=tenant.name,
            tier=tier,
        )
    except Exception:
        pass


async def _on_subscription_updated(tenant: Tenant, data: dict, db: AsyncSession) -> None:
    price_id = _get_price_id_from_sub(data)
    plan = plan_from_paddle_price(price_id) if price_id else None
    if plan:
        await update_tenant(db, tenant,
            tier=plan.tier,
            plan_query_limit=plan.query_limit,
            plan_ai_ops_limit=plan.ai_ops_limit,
        )
        await db.commit()
        logger.info("subscription_upgraded", tenant_id=str(tenant.id), tier=plan.tier)


async def _on_subscription_cancelled(tenant: Tenant, data: dict, db: AsyncSession) -> None:
    await update_tenant(db, tenant, subscription_status="cancelled")
    await db.commit()
    logger.info("subscription_cancelled", tenant_id=str(tenant.id))

    try:
        from eshopeo.notifications.email import send_cancellation_email
        send_cancellation_email.delay(
            email=tenant.billing_email or "",
            store_name=tenant.name,
        )
    except Exception:
        pass


async def _on_payment_failed(tenant: Tenant, data: dict, db: AsyncSession) -> None:
    await update_tenant(db, tenant, subscription_status="paused")
    await db.commit()
    logger.warning("subscription_payment_failed", tenant_id=str(tenant.id))

    try:
        from eshopeo.notifications.email import send_payment_failed_email
        send_payment_failed_email.delay(
            email=tenant.billing_email or "",
            store_name=tenant.name,
        )
    except Exception:
        pass


async def _on_subscription_resumed(tenant: Tenant, data: dict, db: AsyncSession) -> None:
    await update_tenant(db, tenant, subscription_status="active")
    await db.commit()
    logger.info("subscription_resumed", tenant_id=str(tenant.id))


def _get_price_id_from_sub(data: dict) -> str | None:
    items = data.get("items") or []
    if items:
        return items[0].get("price", {}).get("id")
    return None


@router.get("/status", response_model=BillingStatusResponse)
async def billing_status(
    tenant: Tenant = Depends(get_tenant),
) -> BillingStatusResponse:
    """Return the current subscription state for the authenticated tenant."""
    from datetime import datetime, timezone
    import redis as sync_redis

    days_remaining: int | None = None
    trial_ends_iso: str | None = None

    if tenant.trial_ends_at:
        trial_ends_iso = tenant.trial_ends_at.isoformat()
        delta = tenant.trial_ends_at - datetime.now(timezone.utc)
        days_remaining = max(0, delta.days)

    plan = get_plan(tenant.tier)
    query_limit = get_query_limit(tenant.tier, tenant.plan_query_limit)
    ai_ops_lim = get_ai_ops_limit(tenant.tier, tenant.plan_ai_ops_limit)

    # Read current month's AI ops count from Redis
    ai_ops_used = 0
    try:
        s = get_settings()
        r = sync_redis.from_url(str(s.redis_url), decode_responses=True)
        month_key = datetime.now(timezone.utc).strftime("%Y-%m")
        val = r.get(f"ai_ops:{tenant.id}:{month_key}")
        if val:
            ai_ops_used = int(val)
    except Exception:
        pass

    return BillingStatusResponse(
        tier=tenant.tier,
        subscription_status=tenant.subscription_status,
        trial_ends_at=trial_ends_iso,
        days_remaining=days_remaining,
        plan_display_name=plan.display_name,
        query_limit=query_limit,
        paddle_subscription_id=tenant.paddle_subscription_id,
        ai_ops_used=ai_ops_used,
        ai_ops_limit=ai_ops_lim,
        byok_enabled=bool(tenant.anthropic_api_key_enc),
    )


@router.post("/portal", response_model=PortalResponse)
async def customer_portal(
    tenant: Tenant = Depends(get_tenant),
) -> PortalResponse:
    """Generate a Paddle customer portal URL for the tenant."""
    if not tenant.paddle_customer_id:
        raise HTTPException(
            status_code=404,
            detail="No active Paddle subscription found.",
        )
    paddle = _get_paddle_client()
    url = await paddle.get_customer_portal_url(tenant.paddle_customer_id)
    return PortalResponse(portal_url=url)


@router.post("/cancel", status_code=status.HTTP_204_NO_CONTENT)
async def cancel_subscription(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> None:
    """Cancel the tenant's subscription at the end of the current billing period."""
    if not tenant.paddle_subscription_id:
        raise HTTPException(status_code=404, detail="No active subscription.")
    paddle = _get_paddle_client()
    await paddle.cancel_subscription(tenant.paddle_subscription_id)
    await update_tenant(db, tenant, subscription_status="cancelled")
    await db.commit()
    _invalidate_quota_cache(str(tenant.id))


class ByokRequest(BaseModel):
    anthropic_api_key: str | None  # None = clear BYOK


@router.put("/byok", status_code=status.HTTP_204_NO_CONTENT)
async def set_byok_key(
    body: ByokRequest,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> None:
    """Store (or clear) a tenant's own Anthropic API key for BYOK."""
    s = get_settings()
    enc: bytes | None = None
    if body.anthropic_api_key:
        from cryptography.fernet import Fernet
        f = Fernet(s.credential_encryption_key.get_secret_value().encode())
        enc = f.encrypt(body.anthropic_api_key.encode())

    await update_tenant(db, tenant, anthropic_api_key_enc=enc)
    await db.commit()
    logger.info("byok_updated", tenant_id=str(tenant.id), cleared=enc is None)
