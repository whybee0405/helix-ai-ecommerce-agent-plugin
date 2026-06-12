import hashlib

import redis.asyncio as aioredis

from helix.config import Settings


def _cache_key(model_id: str, system: str, user: str) -> str:
    h = hashlib.sha256(f"{model_id}:{system}:{user}".encode()).hexdigest()
    return f"llm:response:{h}"


class LLMCache:
    def __init__(self, settings: Settings) -> None:
        self._redis = aioredis.from_url(str(settings.redis_url), decode_responses=True)

    async def get(self, model_id: str, system: str, user: str) -> str | None:
        return await self._redis.get(_cache_key(model_id, system, user))

    async def set(self, model_id: str, system: str, user: str, value: str, ttl: int) -> None:
        await self._redis.setex(_cache_key(model_id, system, user), ttl, value)

    async def aclose(self) -> None:
        await self._redis.aclose()
