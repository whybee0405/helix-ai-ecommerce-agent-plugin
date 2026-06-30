from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Tenant
from tests.conftest import make_test_settings


def test_inventory_snapshot_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_snapshot = {
        "total": 150,
        "in_stock": 142,
        "out_of_stock": 8,
        "in_stock_rate": 0.95,
    }

    with patch(
        "eshopeo.api.routers.analytics.get_inventory_snapshot",
        new_callable=AsyncMock,
        return_value=mock_snapshot,
    ):
        r = client.get("/v1/analytics/products/inventory")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["total"] == 150
    assert data["in_stock"] == 142
    assert data["in_stock_rate"] == 0.95


def test_inventory_snapshot_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/products/inventory")

    assert r.status_code == 401


def test_inventory_snapshot_zero_products():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_snapshot = {
        "total": 0,
        "in_stock": 0,
        "out_of_stock": 0,
        "in_stock_rate": 1.0,
    }

    with patch(
        "eshopeo.api.routers.analytics.get_inventory_snapshot",
        new_callable=AsyncMock,
        return_value=mock_snapshot,
    ):
        r = client.get("/v1/analytics/products/inventory")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["in_stock_rate"] == 1.0
