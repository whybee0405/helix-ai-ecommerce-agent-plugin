from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Product, Tenant
from tests.conftest import make_test_settings


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    return t


def _make_product(tenant_id):
    p = MagicMock(spec=Product)
    p.id = uuid4()
    p.tenant_id = tenant_id
    p.platform_id = "prod-123"
    p.title = "COSRX Snail Cream"
    p.description_html = "<p>Original</p>"
    p.price_minor = 2500
    p.currency = "USD"
    p.in_stock = True
    p.categories = ["moisturizer"]
    p.domain_attributes = {"skin_type": "all"}
    return p


def test_get_product_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product = _make_product(tenant.id)
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("eshopeo.api.routers.products.get_product_by_id", new_callable=AsyncMock, return_value=product):
        r = client.get(f"/v1/products/{product.id}")

    app.dependency_overrides.clear()
    assert r.status_code == 200
    assert r.json()["title"] == "COSRX Snail Cream"
    assert "description_html" in r.json()


def test_get_product_404_when_not_found():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("eshopeo.api.routers.products.get_product_by_id", new_callable=AsyncMock, return_value=None):
        r = client.get(f"/v1/products/{uuid4()}")

    app.dependency_overrides.clear()
    assert r.status_code == 404


def test_patch_product_returns_updated_product():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product = _make_product(tenant.id)
    product.title = "Updated Title"

    mock_db = AsyncMock()
    mock_db.commit = AsyncMock()

    app.dependency_overrides[get_tenant] = lambda: tenant
    app.dependency_overrides[__import__("eshopeo.api.deps", fromlist=["get_db"]).get_db] = lambda: mock_db

    with patch("eshopeo.api.routers.products.update_product", new_callable=AsyncMock, return_value=product):
        r = client.patch(f"/v1/products/{product.id}", json={"title": "Updated Title"})

    app.dependency_overrides.clear()
    assert r.status_code == 200
    assert r.json()["title"] == "Updated Title"
