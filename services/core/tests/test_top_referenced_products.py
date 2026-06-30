from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Tenant
from tests.conftest import make_test_settings


def test_top_referenced_products_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_products = [
        {"product_id": str(uuid4()), "count": 15},
        {"product_id": str(uuid4()), "count": 9},
    ]

    with patch(
        "eshopeo.api.routers.analytics.get_top_referenced_products",
        new_callable=AsyncMock,
        return_value=mock_products,
    ):
        r = client.get("/v1/analytics/products/top")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["products"]) == 2
    assert data["products"][0]["count"] == 15


def test_top_referenced_products_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/products/top")

    assert r.status_code == 401


def test_top_referenced_products_empty_list():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch(
        "eshopeo.api.routers.analytics.get_top_referenced_products",
        new_callable=AsyncMock,
        return_value=[],
    ):
        r = client.get("/v1/analytics/products/top")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["products"] == []
