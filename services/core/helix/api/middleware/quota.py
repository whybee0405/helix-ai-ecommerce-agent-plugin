from __future__ import annotations

from datetime import datetime, timezone

import redis.asyncio as aioredis
import structlog
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import JSONResponse

from helix.billing.plans import get_query_limit
from helix.config import Settings

logger = structlog.get_logger(__name__)

_WIDGET_PATHS = {"/v1/widget/chat", "/v1/widget/chat/stream", "/v1/widget/routine"}
_COUNTER_TTL  = 32 * 24 * 3600   # 32 days — outlasts any billing month
_LIMIT_TTL    = 3_600             # cache plan limit 1 h
_STATUS_TTL   = 300               # cache subscription status 5 min


def _extract_public_key(authorization: str | None) -> str | None:
    if not authorization or not authorization.startswith("Bearer "):
        return None
    return authorization.removeprefix("Bearer ").strip()


class QuotaMiddleware(BaseHTTPMiddleware):
    def __init__(self, app, settings: Settings) -> None:
        super().__init__(app)
        self._redis = aioredis.from_url(str(settings.redis_url), decode_responses=True)

    async def dispatch(self, request: Request, call_next):
        if request.url.path not in _WIDGET_PATHS:
            return await call_next(request)

        public_key = _extract_public_key(request.headers.get("authorization"))
        if not public_key:
            return await call_next(request)

        # Resolve tenant_id from public_key (Redis cache → DB fallback).
        tenant_id = await self._resolve_tenant_id(public_key)
        if not tenant_id:
            return await call_next(request)

        # Internal/dev tenants bypass all quota and subscription checks.
        if await self._is_internal(tenant_id):
            return await call_next(request)

        # Check subscription status — block paused/cancelled tenants.
        status_result = await self._check_status(tenant_id)
        if status_result is not None:
            return status_result

        # Enforce monthly query quota.
        month_key = datetime.now(timezone.utc).strftime("%Y-%m")
        counter_key = f"quota:{tenant_id}:{month_key}"
        limit = await self._get_limit(tenant_id)

        try:
            count = await self._redis.incr(counter_key)
            if count == 1:
                await self._redis.expire(counter_key, _COUNTER_TTL)
            if count > limit:
                logger.warning("quota_exceeded", tenant_id=tenant_id, count=count, limit=limit)
                return JSONResponse(
                    status_code=429,
                    content={"detail": "Monthly query limit exceeded. Upgrade your plan at helix.cloudia.co.za"},
                    headers={"X-Quota-Exceeded": "monthly"},
                )
        except Exception:
            pass  # Redis failure → allow request through

        return await call_next(request)

    # ── Helpers ───────────────────────────────────────────────────────────────

    async def _is_internal(self, tenant_id: str) -> bool:
        """Return True for internal/dev tenants — they bypass all quota checks."""
        cache_key = f"tenant_internal:{tenant_id}"
        try:
            cached = await self._redis.get(cache_key)
            if cached is not None:
                return cached == "1"
        except Exception:
            pass
        try:
            from uuid import UUID
            from sqlalchemy import select
            from helix.db.engine import async_session_factory
            from helix.db.models import Tenant
            async with async_session_factory() as session:
                row = await session.execute(
                    select(Tenant.is_internal).where(Tenant.id == UUID(tenant_id))
                )
                result = row.scalar_one_or_none()
                is_int = bool(result)
                await self._redis.setex(cache_key, _LIMIT_TTL, "1" if is_int else "0")
                return is_int
        except Exception:
            return False

    async def _resolve_tenant_id(self, public_key: str) -> str | None:
        """Map public_key UUID → tenant UUID, cached permanently (keys don't change)."""
        cache_key = f"pk_tenant:{public_key}"
        try:
            cached = await self._redis.get(cache_key)
            if cached:
                return cached
        except Exception:
            pass

        tenant_id = await self._load_tenant_id_from_db(public_key)
        if tenant_id:
            try:
                await self._redis.set(cache_key, tenant_id)
            except Exception:
                pass
        return tenant_id

    async def _load_tenant_id_from_db(self, public_key: str) -> str | None:
        try:
            from uuid import UUID
            from sqlalchemy import select
            from helix.db.engine import async_session_factory
            from helix.db.models import Tenant
            async with async_session_factory() as session:
                row = await session.execute(
                    select(Tenant.id).where(Tenant.public_key == UUID(public_key))
                )
                result = row.scalar_one_or_none()
                return str(result) if result else None
        except Exception as exc:
            logger.warning("quota_db_lookup_failed", error=str(exc))
            return None

    async def _get_limit(self, tenant_id: str) -> int:
        """Return the effective monthly query limit, Redis-cached for 1h."""
        cache_key = f"quota_limit:{tenant_id}"
        try:
            cached = await self._redis.get(cache_key)
            if cached:
                return int(cached)
        except Exception:
            pass

        limit = await self._load_limit_from_db(tenant_id)
        try:
            await self._redis.setex(cache_key, _LIMIT_TTL, str(limit))
        except Exception:
            pass
        return limit

    async def _load_limit_from_db(self, tenant_id: str) -> int:
        try:
            from uuid import UUID
            from sqlalchemy import select
            from helix.db.engine import async_session_factory
            from helix.db.models import Tenant
            async with async_session_factory() as session:
                row = await session.execute(
                    select(Tenant.tier, Tenant.plan_query_limit)
                    .where(Tenant.id == UUID(tenant_id))
                )
                result = row.one_or_none()
                if result:
                    return get_query_limit(result.tier, result.plan_query_limit)
        except Exception as exc:
            logger.warning("quota_limit_db_failed", error=str(exc))
        return 100  # safe fallback

    async def _check_status(self, tenant_id: str) -> JSONResponse | None:
        """Return a 402/403 response if the tenant should be blocked, else None."""
        cache_key = f"quota_status:{tenant_id}"
        try:
            cached = await self._redis.get(cache_key)
            if cached:
                return self._status_to_response(cached)
        except Exception:
            pass

        status_str = await self._load_status_from_db(tenant_id)
        try:
            await self._redis.setex(cache_key, _STATUS_TTL, status_str)
        except Exception:
            pass
        return self._status_to_response(status_str)

    async def _load_status_from_db(self, tenant_id: str) -> str:
        """Return 'ok', 'trial_expired', 'paused', or 'cancelled'."""
        try:
            from uuid import UUID
            from sqlalchemy import select
            from helix.db.engine import async_session_factory
            from helix.db.models import Tenant
            async with async_session_factory() as session:
                row = await session.execute(
                    select(Tenant.subscription_status, Tenant.trial_ends_at)
                    .where(Tenant.id == UUID(tenant_id))
                )
                result = row.one_or_none()
                if not result:
                    return "ok"
                sub_status, trial_ends_at = result.subscription_status, result.trial_ends_at
                if sub_status == "active":
                    return "ok"
                if sub_status == "trialing":
                    if trial_ends_at and datetime.now(timezone.utc) > trial_ends_at:
                        return "trial_expired"
                    return "ok"
                if sub_status in ("paused", "past_due"):
                    return "paused"
                if sub_status == "cancelled":
                    return "cancelled"
        except Exception as exc:
            logger.warning("quota_status_db_failed", error=str(exc))
        return "ok"

    @staticmethod
    def _status_to_response(status_str: str) -> JSONResponse | None:
        if status_str == "trial_expired":
            return JSONResponse(
                status_code=402,
                content={"detail": "Your free trial has ended. Subscribe at helix.cloudia.co.za to restore access."},
                headers={"X-Helix-Status": "trial_expired"},
            )
        if status_str == "paused":
            return JSONResponse(
                status_code=402,
                content={"detail": "Your subscription is paused due to a failed payment. Update your payment method at helix.cloudia.co.za"},
                headers={"X-Helix-Status": "paused"},
            )
        if status_str == "cancelled":
            return JSONResponse(
                status_code=402,
                content={"detail": "Your subscription has been cancelled. Resubscribe at helix.cloudia.co.za"},
                headers={"X-Helix-Status": "cancelled"},
            )
        return None
