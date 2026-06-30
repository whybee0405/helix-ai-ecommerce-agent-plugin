from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Tenant
from tests.conftest import make_test_settings


def test_order_analytics_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_analytics = {
        "total_orders": 87,
        "total_revenue_minor": 218500,
        "avg_order_value_minor": 2511,
    }

    with patch(
        "eshopeo.api.routers.analytics.get_order_analytics",
        new_callable=AsyncMock,
        return_value=mock_analytics,
    ):
        r = client.get("/v1/analytics/orders")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["total_orders"] == 87
    assert data["total_revenue_minor"] == 218500
    assert "period" in data


def test_order_analytics_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/orders")

    assert r.status_code == 401


def test_order_analytics_zero_orders():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_analytics = {
        "total_orders": 0,
        "total_revenue_minor": 0,
        "avg_order_value_minor": 0,
    }

    with patch(
        "eshopeo.api.routers.analytics.get_order_analytics",
        new_callable=AsyncMock,
        return_value=mock_analytics,
    ):
        r = client.get("/v1/analytics/orders")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["total_orders"] == 0
    assert r.json()["avg_order_value_minor"] == 0
