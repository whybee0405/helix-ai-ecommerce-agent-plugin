from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

import pytest

from helix.api.middleware.quota import QuotaMiddleware, _extract_tenant_id
from helix.api.auth.tokens import issue_widget_token
from tests.conftest import make_test_settings


def test_extract_tenant_id_from_valid_jwt():
    settings = make_test_settings()
    tenant_id = uuid4()
    token = issue_widget_token(tenant_id, settings.session_secret.get_secret_value())
    extracted = _extract_tenant_id(f"Bearer {token}")
    assert extracted == str(tenant_id)


def test_extract_tenant_id_missing_returns_none():
    assert _extract_tenant_id(None) is None


def test_extract_tenant_id_bad_token_returns_none():
    assert _extract_tenant_id("Bearer not.a.jwt") is None


async def test_quota_allows_request_under_limit():
    settings = make_test_settings(default_monthly_query_limit=100)
    tenant_id = uuid4()
    token = issue_widget_token(tenant_id, settings.session_secret.get_secret_value())

    mock_redis = AsyncMock()
    mock_redis.incr.return_value = 50  # under limit

    with patch("helix.api.middleware.quota.aioredis.from_url", return_value=mock_redis):
        middleware = QuotaMiddleware(app=MagicMock(), settings=settings)
        middleware._redis = mock_redis

        mock_request = MagicMock()
        mock_request.url.path = "/v1/widget/chat"
        mock_request.headers.get.return_value = f"Bearer {token}"

        call_next = AsyncMock(return_value=MagicMock(status_code=200))
        response = await middleware.dispatch(mock_request, call_next)

    call_next.assert_called_once()
    assert response.status_code == 200


async def test_quota_blocks_request_over_limit():
    settings = make_test_settings(default_monthly_query_limit=10)
    tenant_id = uuid4()
    token = issue_widget_token(tenant_id, settings.session_secret.get_secret_value())

    mock_redis = AsyncMock()
    mock_redis.incr.return_value = 11  # over the limit of 10

    with patch("helix.api.middleware.quota.aioredis.from_url", return_value=mock_redis):
        middleware = QuotaMiddleware(app=MagicMock(), settings=settings)
        middleware._redis = mock_redis

        mock_request = MagicMock()
        mock_request.url.path = "/v1/widget/chat"
        mock_request.headers.get.return_value = f"Bearer {token}"

        call_next = AsyncMock()
        response = await middleware.dispatch(mock_request, call_next)

    assert response.status_code == 429
    assert response.headers.get("X-Quota-Exceeded") == "monthly"
    call_next.assert_not_called()


async def test_quota_passes_through_non_widget_paths():
    settings = make_test_settings(default_monthly_query_limit=1)
    tenant_id = uuid4()
    token = issue_widget_token(tenant_id, settings.session_secret.get_secret_value())

    mock_redis = AsyncMock()
    mock_redis.incr.return_value = 999

    with patch("helix.api.middleware.quota.aioredis.from_url", return_value=mock_redis):
        middleware = QuotaMiddleware(app=MagicMock(), settings=settings)
        middleware._redis = mock_redis

        mock_request = MagicMock()
        mock_request.url.path = "/v1/health"  # not a widget path
        mock_request.headers.get.return_value = f"Bearer {token}"

        call_next = AsyncMock(return_value=MagicMock(status_code=200))
        response = await middleware.dispatch(mock_request, call_next)

    call_next.assert_called_once()
    assert response.status_code == 200


async def test_quota_fails_open_on_redis_error():
    settings = make_test_settings(default_monthly_query_limit=10)
    tenant_id = uuid4()
    token = issue_widget_token(tenant_id, settings.session_secret.get_secret_value())

    mock_redis = AsyncMock()
    mock_redis.incr.side_effect = Exception("Redis down")

    with patch("helix.api.middleware.quota.aioredis.from_url", return_value=mock_redis):
        middleware = QuotaMiddleware(app=MagicMock(), settings=settings)
        middleware._redis = mock_redis

        mock_request = MagicMock()
        mock_request.url.path = "/v1/widget/chat"
        mock_request.headers.get.return_value = f"Bearer {token}"

        call_next = AsyncMock(return_value=MagicMock(status_code=200))
        response = await middleware.dispatch(mock_request, call_next)

    # Should fail open and call next when Redis is down
    call_next.assert_called_once()
    assert response.status_code == 200
