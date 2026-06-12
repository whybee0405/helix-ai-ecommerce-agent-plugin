from unittest.mock import AsyncMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.routers.admin import _auth_provision
from tests.conftest import make_test_settings


def test_admin_tenant_usage_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    app.dependency_overrides[_auth_provision] = lambda: "test-key"

    tenant_id = uuid4()
    mock_usage = {
        "total_queries": 42,
        "total_cost_usd": 0.031200,
        "total_tokens_in": 15000,
        "total_tokens_out": 6000,
    }

    with patch(
        "helix.api.routers.admin.get_tenant_usage_summary",
        new_callable=AsyncMock,
        return_value=mock_usage,
    ):
        r = client.get(f"/v1/admin/tenants/{tenant_id}/usage")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["total_queries"] == 42
    assert data["tenant_id"] == str(tenant_id)
    assert "month" in data


def test_admin_tenant_usage_requires_provision_key():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get(f"/v1/admin/tenants/{uuid4()}/usage")

    assert r.status_code == 401


def test_admin_tenant_usage_zero_when_no_events():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    app.dependency_overrides[_auth_provision] = lambda: "test-key"

    mock_usage = {
        "total_queries": 0,
        "total_cost_usd": 0.0,
        "total_tokens_in": 0,
        "total_tokens_out": 0,
    }

    with patch(
        "helix.api.routers.admin.get_tenant_usage_summary",
        new_callable=AsyncMock,
        return_value=mock_usage,
    ):
        r = client.get(f"/v1/admin/tenants/{uuid4()}/usage")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["total_queries"] == 0
