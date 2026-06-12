import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from httpx import AsyncClient, ASGITransport

from helix.api.app import create_app
from helix.db.models import Product, Tenant
from tests.conftest import make_test_settings


@pytest.fixture
def tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    t.public_key = uuid4()
    return t


@pytest.fixture
def app():
    return create_app(make_test_settings())


@pytest.fixture
async def client(app):
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        yield c


async def test_search_requires_auth(client):
    resp = await client.get("/v1/search/products", params={"q": "serum"})
    assert resp.status_code == 401


async def test_search_returns_results(client, tenant):
    fake_product = MagicMock(spec=Product)
    fake_product.id = uuid4()
    fake_product.platform_id = "42"
    fake_product.title = "Snail Mucin Essence"
    fake_product.price_minor = 34900
    fake_product.currency = "ZAR"
    fake_product.in_stock = True
    fake_product.categories = ["Essence"]
    fake_product.domain_attributes = {}

    with patch("helix.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant), \
         patch("helix.api.routers.search.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("helix.api.routers.search.vector_search_products", new_callable=AsyncMock, return_value=[(fake_product, 0.91)]):

        resp = await client.get(
            "/v1/search/products",
            params={"q": "hydration serum"},
            headers={"X-Helix-Tenant-Key": str(tenant.public_key)},
        )

    assert resp.status_code == 200
    data = resp.json()
    assert data["total"] == 1
    assert data["results"][0]["title"] == "Snail Mucin Essence"
    assert data["results"][0]["score"] == 0.91


async def test_search_empty_query_returns_422(client, tenant):
    with patch("helix.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant):
        resp = await client.get(
            "/v1/search/products",
            params={"q": ""},
            headers={"X-Helix-Tenant-Key": str(tenant.public_key)},
        )
    assert resp.status_code == 422
