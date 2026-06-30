import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from eshopeo.api.middleware.rate_limit import RateLimitMiddleware, _extract_tenant_id
from eshopeo.api.auth.tokens import issue_widget_token
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


async def test_rate_limit_allows_requests_under_limit():
    settings = make_test_settings()
    tenant_id = uuid4()
    token = issue_widget_token(tenant_id, settings.session_secret.get_secret_value())

    mock_redis = AsyncMock()
    mock_redis.incr.return_value = 1  # first request

    with patch("eshopeo.api.middleware.rate_limit.aioredis.from_url", return_value=mock_redis):
        middleware = RateLimitMiddleware(app=MagicMock(), settings=settings)
        middleware._redis = mock_redis

        mock_request = MagicMock()
        mock_request.url.path = "/v1/widget/chat"
        mock_request.headers.get.return_value = f"Bearer {token}"

        call_next = AsyncMock(return_value=MagicMock(status_code=200))
        response = await middleware.dispatch(mock_request, call_next)

    call_next.assert_called_once()


async def test_rate_limit_blocks_requests_over_limit():
    settings = make_test_settings()
    tenant_id = uuid4()
    token = issue_widget_token(tenant_id, settings.session_secret.get_secret_value())

    mock_redis = AsyncMock()
    mock_redis.incr.return_value = 31  # over the default limit of 30

    with patch("eshopeo.api.middleware.rate_limit.aioredis.from_url", return_value=mock_redis):
        middleware = RateLimitMiddleware(app=MagicMock(), settings=settings)
        middleware._redis = mock_redis

        mock_request = MagicMock()
        mock_request.url.path = "/v1/widget/chat"
        mock_request.headers.get.return_value = f"Bearer {token}"

        call_next = AsyncMock()
        response = await middleware.dispatch(mock_request, call_next)

    assert response.status_code == 429
    call_next.assert_not_called()
