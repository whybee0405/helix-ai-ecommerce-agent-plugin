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
    return t


def _make_product():
    p = MagicMock(spec=Product)
    p.id = uuid4()
    return p


def test_bulk_generate_queues_products():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    products = [_make_product(), _make_product(), _make_product()]
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_task = MagicMock()
    with (
        patch("helix.api.routers.content.list_products_without_draft", new_callable=AsyncMock, return_value=products),
        patch("helix.api.routers.content.generate_description", mock_task),
    ):
        r = client.post("/v1/content/bulk-generate")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["queued"] == 3
    assert mock_task.delay.call_count == 3


def test_bulk_generate_returns_zero_when_all_have_drafts():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("helix.api.routers.content.list_products_without_draft", new_callable=AsyncMock, return_value=[]):
        r = client.post("/v1/content/bulk-generate")

    app.dependency_overrides.clear()
    assert r.status_code == 200
    assert r.json()["queued"] == 0


def test_bulk_generate_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.post("/v1/content/bulk-generate")
    assert r.status_code == 401
