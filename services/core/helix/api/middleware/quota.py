from datetime import datetime, timezone

import redis.asyncio as aioredis
import structlog
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import JSONResponse

from helix.config import Settings

logger = structlog.get_logger(__name__)

_WIDGET_PATHS = {"/v1/widget/chat", "/v1/widget/routine"}
_TTL_SECONDS = 32 * 24 * 3600


def _extract_tenant_id(authorization: str | None) -> str | None:
    if not authorization or not authorization.startswith("Bearer "):
        return None
    token = authorization.removeprefix("Bearer ")
    try:
        from jose import jwt as _jwt
        payload = _jwt.get_unverified_claims(token)
        return payload.get("tenant_id")
    except Exception:
        return None


class QuotaMiddleware(BaseHTTPMiddleware):
    def __init__(self, app, settings: Settings) -> None:
        super().__init__(app)
        self._redis = aioredis.from_url(str(settings.redis_url), decode_responses=True)
        self._limit = settings.default_monthly_query_limit

    async def dispatch(self, request: Request, call_next):
        if request.url.path not in _WIDGET_PATHS:
            return await call_next(request)

        tenant_id = _extract_tenant_id(request.headers.get("authorization"))
        if tenant_id is None:
            return await call_next(request)

        month_key = datetime.now(timezone.utc).strftime("%Y-%m")
        key = f"quota:{tenant_id}:{month_key}"
        try:
            count = await self._redis.incr(key)
            if count == 1:
                await self._redis.expire(key, _TTL_SECONDS)
            if count > self._limit:
                logger.warning("quota_exceeded", tenant_id=tenant_id, count=count, limit=self._limit)
                return JSONResponse(
                    status_code=429,
                    content={"detail": "Monthly query limit exceeded"},
                    headers={"X-Quota-Exceeded": "monthly"},
                )
        except Exception:
            pass

        return await call_next(request)
