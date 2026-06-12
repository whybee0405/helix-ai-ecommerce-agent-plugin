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
    p.title = "Hydrating Serum"
    p.price_minor = 2500
    p.currency = "USD"
    p.in_stock = True
    p.categories = ["serum"]
    p.domain_attributes = {}
    return p


def test_similar_products_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    product_id = uuid4()
    mock_rows = [
        (_make_mock_product(), 0.92),
        (_make_mock_product(), 0.87),
    ]

    with patch(
        "helix.api.routers.search.get_similar_products",
        new_callable=AsyncMock,
        return_value=mock_rows,
    ):
        r = client.get(f"/v1/search/similar/{product_id}")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["results"]) == 2
    assert data["total"] == 2


def test_similar_products_404_when_no_embedding():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch(
        "helix.api.routers.search.get_similar_products",
        new_callable=AsyncMock,
        return_value=[],
    ):
        r = client.get(f"/v1/search/similar/{uuid4()}")

    app.dependency_overrides.clear()

    assert r.status_code == 404


def test_similar_products_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get(f"/v1/search/similar/{uuid4()}")

    assert r.status_code == 401
