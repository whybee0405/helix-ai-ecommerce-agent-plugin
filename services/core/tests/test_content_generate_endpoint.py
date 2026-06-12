from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Product, Tenant
from tests.conftest import make_test_settings


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    t.public_key = uuid4()
    return t


def _make_product(tenant_id):
    p = MagicMock(spec=Product)
    p.id = uuid4()
    p.tenant_id = tenant_id
    return p


def test_generate_returns_202():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product = _make_product(tenant.id)
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_task = MagicMock()
    with (
        patch("helix.api.routers.content.get_product_by_id", new_callable=AsyncMock, return_value=product),
        patch("helix.api.routers.content.generate_description", mock_task),
    ):
        r = client.post(f"/v1/content/products/{product.id}/generate")

    app.dependency_overrides.clear()

    assert r.status_code == 202
    assert r.json()["queued"] is True
    mock_task.delay.assert_called_once_with(str(tenant.id), str(product.id))


def test_generate_404_on_unknown_product():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("helix.api.routers.content.get_product_by_id", new_callable=AsyncMock, return_value=None):
        r = client.post(f"/v1/content/products/{uuid4()}/generate")

    app.dependency_overrides.clear()
    assert r.status_code == 404


def test_generate_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.post(f"/v1/content/products/{uuid4()}/generate")
    assert r.status_code == 401
