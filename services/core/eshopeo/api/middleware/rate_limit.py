import structlog
import redis.asyncio as aioredis
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import JSONResponse

from eshopeo.config import Settings

logger = structlog.get_logger(__name__)

_WIDGET_PATHS = {"/v1/widget/chat", "/v1/widget/routine"}


def _extract_tenant_id(authorization: str | None) -> str | None:
    """Extract tenant_id from a JWT without full validation (rate-limit keying only)."""
    if not authorization or not authorization.startswith("Bearer "):
        return None
    token = authorization.removeprefix("Bearer ")
    try:
        from jose import jwt as _jwt
        payload = _jwt.get_unverified_claims(token)
        return payload.get("tenant_id")
    except Exception:
        return None


class RateLimitMiddleware(BaseHTTPMiddleware):
    def __init__(self, app, settings: Settings) -> None:
        super().__init__(app)
        self._settings = settings
        self._redis = aioredis.from_url(str(settings.redis_url), decode_responses=True)
        self._limit = settings.widget_rate_limit
        self._window = 60

    async def dispatch(self, request: Request, call_next):
        if request.url.path not in _WIDGET_PATHS:
            return await call_next(request)

        tenant_id = _extract_tenant_id(request.headers.get("authorization"))
        if tenant_id is None:
            return await call_next(request)

        key = f"ratelimit:{tenant_id}:{request.url.path.replace('/', '_')}"
        try:
            count = await self._redis.incr(key)
            if count == 1:
                await self._redis.expire(key, self._window)
            if count > self._limit:
                logger.warning("rate_limit_exceeded", tenant_id=tenant_id, path=request.url.path, count=count)
                return JSONResponse(
                    status_code=429,
                    content={"detail": "Rate limit exceeded"},
                    headers={"Retry-After": str(self._window)},
                )
        except Exception:
            pass

        return await call_next(request)
