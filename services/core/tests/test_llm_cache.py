import pytest
from unittest.mock import AsyncMock, patch

from eshopeo.llm.cache import LLMCache, _cache_key
from tests.conftest import make_test_settings


def test_cache_key_deterministic():
    k1 = _cache_key("model", "sys", "user")
    k2 = _cache_key("model", "sys", "user")
    assert k1 == k2


def test_cache_key_differs_on_input():
    k1 = _cache_key("model", "sys", "user1")
    k2 = _cache_key("model", "sys", "user2")
    assert k1 != k2


async def test_cache_get_miss_returns_none():
    settings = make_test_settings()
    with patch("eshopeo.llm.cache.aioredis.from_url") as mock_redis_cls:
        mock_redis = AsyncMock()
        mock_redis.get.return_value = None
        mock_redis_cls.return_value = mock_redis

        cache = LLMCache(settings)
        result = await cache.get("model", "sys", "user")
        assert result is None


async def test_cache_get_hit_returns_value():
    settings = make_test_settings()
    with patch("eshopeo.llm.cache.aioredis.from_url") as mock_redis_cls:
        mock_redis = AsyncMock()
        mock_redis.get.return_value = '{"intent": "product_search", "confidence": 0.9}'
        mock_redis_cls.return_value = mock_redis

        cache = LLMCache(settings)
        result = await cache.get("model", "sys", "user")
        assert result is not None
        assert "product_search" in result
