from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Product, Tenant
from tests.conftest import make_test_settings


def _make_mock_product():
    p = MagicMock(spec=Product)
    p.id = uuid4()
    p.platform_id = "prod-1"
    p.title = "Toner"
    p.price_minor = 1800
    p.currency = "USD"
    p.in_stock = True
    p.categories = ["toner"]
    p.domain_attributes = {}
    return p


def test_browse_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_products = [_make_mock_product(), _make_mock_product()]

    with patch(
        "helix.api.routers.search.browse_products",
        new_callable=AsyncMock,
        return_value=(mock_products, 2),
    ):
        r = client.get("/v1/search/browse")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["products"]) == 2
    assert data["total"] == 2


def test_browse_empty_catalog():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch(
        "helix.api.routers.search.browse_products",
        new_callable=AsyncMock,
        return_value=([], 0),
    ):
        r = client.get("/v1/search/browse")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["products"] == []
    assert r.json()["total"] == 0


def test_browse_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/search/browse")

    assert r.status_code == 401
