import redis.asyncio as aioredis
from fastapi import APIRouter
from sqlalchemy import text

router = APIRouter(tags=["ops"])


@router.get("/health")
async def health() -> dict:
    db_ok = False
    redis_ok = False
    try:
        from eshopeo.db.engine import async_session_factory

        async with async_session_factory() as session:
            await session.execute(text("SELECT 1"))
        db_ok = True
    except Exception:
        pass
    try:
        from eshopeo.config import get_settings

        settings = get_settings()
        r = aioredis.from_url(str(settings.redis_url))
        await r.ping()
        await r.aclose()
        redis_ok = True
    except Exception:
        pass
    return {
        "status": "ok" if (db_ok and redis_ok) else "degraded",
        "db": db_ok,
        "redis": redis_ok,
    }
