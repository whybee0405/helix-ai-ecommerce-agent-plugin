from unittest.mock import AsyncMock, patch

import pytest
from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from tests.conftest import make_test_settings


@pytest.fixture
def client():
    settings = make_test_settings()
    app = create_app(settings)
    return TestClient(app)


def test_admin_stats_returns_data(client):
    """Test that valid provision key returns 200 with platform stats."""
    mock_stats = {
        "total_tenants": 5,
        "total_products": 200,
        "total_customers": 50,
        "queries_this_month": 1000,
        "cost_this_month_usd": 2.50,
    }

    with patch("eshopeo.api.routers.admin.get_platform_stats", new_callable=AsyncMock) as mock:
        mock.return_value = mock_stats

        response = client.get(
            "/v1/admin/stats",
            headers={"X-eShopeo-Provision-Key": "test-provision-key"},
        )

    assert response.status_code == 200
    data = response.json()
    assert data["total_tenants"] == 5
    assert data["total_products"] == 200
    assert data["total_customers"] == 50
    assert data["cost_this_month_usd"] == 2.50
    assert "queries_this_month" in data


def test_admin_stats_401_bad_key(client):
    """Test that wrong provision key returns 401."""
    response = client.get(
        "/v1/admin/stats",
        headers={"X-eShopeo-Provision-Key": "wrong-key"},
    )

    assert response.status_code == 401


def test_admin_stats_401_missing_key(client):
    """Test that missing provision key returns 401."""
    response = client.get("/v1/admin/stats")

    assert response.status_code == 401
