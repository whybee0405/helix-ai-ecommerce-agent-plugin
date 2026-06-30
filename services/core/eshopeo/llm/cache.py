import hashlib
from typing import Optional

import redis.asyncio as aioredis

from eshopeo.config import Settings


def _cache_key(namespace: str, model_id: str, system: str, user: str) -> str:
    h = hashlib.sha256(f"{namespace}:{model_id}:{system}:{user}".encode()).hexdigest()
    return f"llm:response:{h}"


def _semantic_key(namespace: str, model_id: str, vector_hash: str) -> str:
    return f"llm:sem:{namespace}:{model_id}:{vector_hash}"


class LLMCache:
    """Exact-match Redis cache for LLM responses, namespaced per-tenant + branding version
    so two tenants with paraphrasing-overlapping queries don't see each other's responses."""

    def __init__(self, settings: Settings) -> None:
        self._redis = aioredis.from_url(str(settings.redis_url), decode_responses=True)

    async def get(
        self,
        model_id: str,
        system: str,
        user: str,
        namespace: str = "",
    ) -> Optional[str]:
        return await self._redis.get(_cache_key(namespace, model_id, system, user))

    async def set(
        self,
        model_id: str,
        system: str,
        user: str,
        value: str,
        ttl: int,
        namespace: str = "",
    ) -> None:
        await self._redis.setex(
            _cache_key(namespace, model_id, system, user), ttl, value
        )

    async def aclose(self) -> None:
        await self._redis.aclose()
