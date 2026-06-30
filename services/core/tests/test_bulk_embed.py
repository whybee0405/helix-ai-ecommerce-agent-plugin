from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Product, Tenant
from tests.conftest import make_test_settings


def _make_mock_product():
    p = MagicMock(spec=Product)
    p.id = uuid4()
    p.tenant_id = uuid4()
    return p


def test_bulk_embed_queues_products():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_products = [_make_mock_product() for _ in range(3)]
    mock_task = MagicMock()

    with (
        patch(
            "eshopeo.api.routers.jobs.list_products_without_embedding",
            new_callable=AsyncMock,
            return_value=mock_products,
        ),
        patch("eshopeo.api.routers.jobs.embed_product", mock_task),
    ):
        r = client.post("/v1/jobs/embed/bulk")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["queued"] == 3
    assert mock_task.delay.call_count == 3


def test_bulk_embed_no_products():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with (
        patch(
            "eshopeo.api.routers.jobs.list_products_without_embedding",
            new_callable=AsyncMock,
            return_value=[],
        ),
        patch("eshopeo.api.routers.jobs.embed_product", MagicMock()),
    ):
        r = client.post("/v1/jobs/embed/bulk")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["queued"] == 0


def test_bulk_embed_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.post("/v1/jobs/embed/bulk")

    assert r.status_code == 401
