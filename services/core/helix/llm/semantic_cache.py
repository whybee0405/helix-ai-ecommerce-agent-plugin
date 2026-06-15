"""Per-tenant semantic response cache.

Stores (query_vector, response, ttl) triples in a Redis sorted set keyed by
`sem:{namespace}:{model_id}`. On lookup, scans entries in that namespace and
returns the response whose vector has the highest cosine similarity to the
query vector, provided it crosses a similarity threshold (default 0.93).

Why a per-namespace scan instead of vector search? Two reasons:
1. Per-namespace TTL is critical for catalog freshness — sorted set with score=expiry
   gives us O(log N) range scans to drop stale entries.
2. The N per namespace is small (~hundreds), so a Python-side cosine over JSON-decoded
   vectors is comfortably under 10ms and avoids a Redis Stack dependency.
"""

from __future__ import annotations

import json
import time
from typing import Optional
from uuid import uuid4

import redis.asyncio as aioredis
import structlog

from helix.config import Settings
from helix.llm.embeddings import embed_text

logger = structlog.get_logger(__name__)

_DEFAULT_THRESHOLD = 0.93
_MAX_ENTRIES_PER_NAMESPACE = 256


class SemanticCache:
    def __init__(self, settings: Settings) -> None:
        self._r = aioredis.from_url(str(settings.redis_url), decode_responses=True)

    async def aclose(self) -> None:
        await self._r.aclose()

    async def lookup(
        self,
        namespace: str,
        model_id: str,
        query: str,
        threshold: float = _DEFAULT_THRESHOLD,
        query_vector: Optional[list[float]] = None,
    ) -> Optional[dict]:
        if query_vector is None:
            query_vector = await embed_text(query)
        if query_vector is None:
            return None

        zkey = self._zkey(namespace, model_id)
        now = int(time.time())
        # drop expired entries opportunistically
        await self._r.zremrangebyscore(zkey, 0, now)

        ids = await self._r.zrange(zkey, 0, -1)
        if not ids:
            return None

        best_score = -1.0
        best_payload: Optional[dict] = None
        hkey = self._hkey(namespace, model_id)
        raws = await self._r.hmget(hkey, ids)
        for raw in raws:
            if not raw:
                continue
            try:
                obj = json.loads(raw)
            except (TypeError, ValueError):
                continue
            sim = _cosine(query_vector, obj.get("v") or [])
            if sim > best_score:
                best_score = sim
                best_payload = obj

        if best_payload and best_score >= threshold:
            logger.info("semantic_cache_hit", namespace=namespace, similarity=round(best_score, 4))
            return {
                "response": best_payload.get("r"),
                "products_referenced": best_payload.get("p", []),
                "source": best_payload.get("s", "semantic_cache"),
                "similarity": best_score,
            }
        return None

    async def store(
        self,
        namespace: str,
        model_id: str,
        query: str,
        response: str,
        products_referenced: list[str] | None = None,
        source: str = "llm",
        ttl: int = 3600,
        query_vector: Optional[list[float]] = None,
    ) -> None:
        if query_vector is None:
            query_vector = await embed_text(query)
        if query_vector is None:
            return

        zkey = self._zkey(namespace, model_id)
        hkey = self._hkey(namespace, model_id)
        entry_id = uuid4().hex
        expiry = int(time.time()) + ttl
        payload = json.dumps({
            "q": query,
            "r": response,
            "p": products_referenced or [],
            "s": source,
            "v": query_vector,
        })
        pipe = self._r.pipeline()
        pipe.zadd(zkey, {entry_id: expiry})
        pipe.hset(hkey, entry_id, payload)
        pipe.expire(zkey, ttl + 3600)
        pipe.expire(hkey, ttl + 3600)
        await pipe.execute()

        size = await self._r.zcard(zkey)
        if size > _MAX_ENTRIES_PER_NAMESPACE:
            # drop the oldest (lowest expiry score) so the namespace stays bounded
            to_remove = await self._r.zrange(zkey, 0, size - _MAX_ENTRIES_PER_NAMESPACE - 1)
            if to_remove:
                pipe = self._r.pipeline()
                pipe.zrem(zkey, *to_remove)
                pipe.hdel(hkey, *to_remove)
                await pipe.execute()

    @staticmethod
    def _zkey(namespace: str, model_id: str) -> str:
        return f"sem:idx:{namespace}:{model_id}"

    @staticmethod
    def _hkey(namespace: str, model_id: str) -> str:
        return f"sem:data:{namespace}:{model_id}"


def _cosine(a: list[float], b: list[float]) -> float:
    if not a or not b or len(a) != len(b):
        return -1.0
    # vectors are L2-normalised at embedding time → dot product == cosine
    s = 0.0
    for x, y in zip(a, b):
        s += x * y
    return s


def ttl_for_intent(intent: str) -> int:
    """Catalogue-sensitive queries get shorter TTLs than policy/identity queries."""
    if intent in ("product_search", "compatibility"):
        return 60 * 60          # 1h — catalog/stock changes
    if intent in ("routine",):
        return 6 * 60 * 60      # 6h
    if intent in ("faq", "other"):
        return 24 * 60 * 60     # 24h — policies, brand info
    return 60 * 60
