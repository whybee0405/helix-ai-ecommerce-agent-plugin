from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Tenant
from tests.conftest import make_test_settings


def test_quota_status_returns_used_count():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_redis = AsyncMock()
    mock_redis.get = AsyncMock(return_value="3421")
    mock_redis.aclose = AsyncMock()

    with patch("eshopeo.api.routers.analytics.aioredis.from_url", return_value=mock_redis):
        r = client.get("/v1/analytics/quota")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    expected_limit = settings.default_monthly_query_limit
    assert data["used"] == 3421
    assert data["limit"] == expected_limit
    assert data["remaining"] == expected_limit - 3421


def test_quota_status_zero_when_key_missing():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_redis = AsyncMock()
    mock_redis.get = AsyncMock(return_value=None)
    mock_redis.aclose = AsyncMock()

    with patch("eshopeo.api.routers.analytics.aioredis.from_url", return_value=mock_redis):
        r = client.get("/v1/analytics/quota")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    expected_limit = settings.default_monthly_query_limit
    assert data["used"] == 0
    assert data["remaining"] == expected_limit


def test_quota_status_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/quota")

    assert r.status_code == 401
