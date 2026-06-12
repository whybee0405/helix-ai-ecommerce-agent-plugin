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
    p.title = "Serum"
    p.price_minor = 2500
    p.currency = "USD"
    p.in_stock = True
    p.categories = ["serum"]
    p.domain_attributes = {}
    return p


def test_search_with_price_filter_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_rows = [(_make_mock_product(), 0.91)]

    with (
        patch(
            "helix.api.routers.search.embed_query",
            new_callable=AsyncMock,
            return_value=[0.1] * 1024,
        ),
        patch(
            "helix.api.routers.search.vector_search_products",
            new_callable=AsyncMock,
            return_value=mock_rows,
        ),
    ):
        r = client.get("/v1/search/products?q=serum&min_price=1000&max_price=5000")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert len(r.json()["results"]) == 1


def test_search_price_filter_passed_to_crud():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_search = AsyncMock(return_value=[])

    with (
        patch("helix.api.routers.search.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024),
        patch("helix.api.routers.search.vector_search_products", mock_search),
    ):
        client.get("/v1/search/products?q=serum&min_price=1000&max_price=5000")

    app.dependency_overrides.clear()

    _, kwargs = mock_search.call_args
    assert kwargs.get("min_price") == 1000
    assert kwargs.get("max_price") == 5000


def test_search_price_filter_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/search/products?q=serum&min_price=1000")

    assert r.status_code == 401
