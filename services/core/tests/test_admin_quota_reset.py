from unittest.mock import AsyncMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.routers.admin import _auth_provision
from tests.conftest import make_test_settings


def test_quota_reset_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    app.dependency_overrides[_auth_provision] = lambda: "test-key"

    tenant_id = uuid4()
    mock_redis = AsyncMock()

    with patch("helix.api.routers.admin.aioredis") as mock_aioredis:
        mock_aioredis.from_url.return_value = mock_redis
        r = client.post(f"/v1/admin/tenants/{tenant_id}/quota/reset")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["reset"] is True
    assert str(tenant_id) in data["key"]


def test_quota_reset_calls_delete():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    app.dependency_overrides[_auth_provision] = lambda: "test-key"

    tenant_id = uuid4()
    mock_redis = AsyncMock()

    with patch("helix.api.routers.admin.aioredis") as mock_aioredis:
        mock_aioredis.from_url.return_value = mock_redis
        client.post(f"/v1/admin/tenants/{tenant_id}/quota/reset")

    app.dependency_overrides.clear()

    mock_redis.delete.assert_called_once()


def test_quota_reset_requires_provision_key():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.post(f"/v1/admin/tenants/{uuid4()}/quota/reset")

    assert r.status_code == 401
