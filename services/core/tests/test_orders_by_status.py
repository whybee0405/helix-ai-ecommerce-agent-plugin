from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def test_orders_by_status_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_statuses = [
        {"status": "paid", "count": 72, "total_revenue_minor": 184320},
        {"status": "pending", "count": 10, "total_revenue_minor": 25100},
    ]

    with patch(
        "helix.api.routers.analytics.get_orders_by_status",
        new_callable=AsyncMock,
        return_value=mock_statuses,
    ):
        r = client.get("/v1/analytics/orders/by-status")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["statuses"]) == 2
    assert data["statuses"][0]["status"] == "paid"
    assert data["statuses"][0]["count"] == 72


def test_orders_by_status_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/orders/by-status")

    assert r.status_code == 401


def test_orders_by_status_empty():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch(
        "helix.api.routers.analytics.get_orders_by_status",
        new_callable=AsyncMock,
        return_value=[],
    ):
        r = client.get("/v1/analytics/orders/by-status")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["statuses"] == []
