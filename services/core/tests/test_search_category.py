import inspect
import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from httpx import AsyncClient, ASGITransport

from helix.api.app import create_app
from helix.db.crud.products import vector_search_products
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


async def test_search_with_category_passes_param(client, tenant):
    """Test that category query parameter is passed to vector_search_products."""
    fake_product = MagicMock(spec=Product)
    fake_product.id = uuid4()
    fake_product.platform_id = "42"
    fake_product.title = "Hydrating Toner"
    fake_product.price_minor = 2500
    fake_product.currency = "USD"
    fake_product.in_stock = True
    fake_product.categories = ["toner", "hydrating"]
    fake_product.domain_attributes = {}

    with patch("helix.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant), \
         patch("helix.api.routers.search.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("helix.api.routers.search.vector_search_products", new_callable=AsyncMock, return_value=[(fake_product, 0.95)]) as mock_search:

        resp = await client.get(
            "/v1/search/products",
            params={"q": "toner", "category": "toner"},
            headers={"X-Helix-Tenant-Key": str(tenant.public_key)},
        )

    assert resp.status_code == 200
    data = resp.json()
    assert data["total"] == 1
    assert data["results"][0]["title"] == "Hydrating Toner"
    # Verify category was passed to vector_search_products
    mock_search.assert_called_once()
    call_kwargs = mock_search.call_args.kwargs
    assert call_kwargs.get("category") == "toner"


async def test_search_without_category_still_works(client, tenant):
    """Test that search works without category parameter."""
    fake_product = MagicMock(spec=Product)
    fake_product.id = uuid4()
    fake_product.platform_id = "43"
    fake_product.title = "Skincare Cream"
    fake_product.price_minor = 3500
    fake_product.currency = "USD"
    fake_product.in_stock = True
    fake_product.categories = ["cream", "skincare"]
    fake_product.domain_attributes = {}

    with patch("helix.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant), \
         patch("helix.api.routers.search.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("helix.api.routers.search.vector_search_products", new_callable=AsyncMock, return_value=[(fake_product, 0.95)]):

        resp = await client.get(
            "/v1/search/products",
            params={"q": "skincare"},
            headers={"X-Helix-Tenant-Key": str(tenant.public_key)},
        )

    assert resp.status_code == 200
    data = resp.json()
    assert data["total"] == 1
    assert data["results"][0]["title"] == "Skincare Cream"


def test_vector_search_has_category_param():
    """Test that vector_search_products function has category parameter."""
    sig = inspect.signature(vector_search_products)
    params = sig.parameters

    # Check that 'category' parameter exists
    assert "category" in params, "category parameter not found in vector_search_products"

    # Check that category has a default value of None
    category_param = params["category"]
    assert category_param.default is None, f"category default should be None, got {category_param.default}"

    # Check that category appears before price range params (added in Phase 13)
    param_names = list(params.keys())
    assert "category" in param_names, f"category not found in param list: {param_names}"
