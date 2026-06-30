from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db, get_tenant
from eshopeo.db.models import ContentDraft, Product, Tenant
from tests.conftest import make_test_settings


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    return t


def _make_draft(tenant_id, product_id, status="pending"):
    d = MagicMock(spec=ContentDraft)
    d.product_id = product_id
    d.tenant_id = tenant_id
    d.field = "description_html"
    d.draft_text = "<p>Generated</p>"
    d.status = status
    d.created_at = datetime(2026, 6, 12, tzinfo=timezone.utc)
    d.approved_at = None
    return d


def test_approve_draft_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product_id = uuid4()
    draft = _make_draft(tenant.id, product_id)
    approved_draft = _make_draft(tenant.id, product_id, status="approved")
    approved_draft.approved_at = datetime(2026, 6, 12, 1, 0, tzinfo=timezone.utc)

    mock_product = MagicMock(spec=Product)
    mock_product.description_html = None

    mock_db = AsyncMock()
    mock_db.add = MagicMock()
    mock_db.commit = AsyncMock()

    app.dependency_overrides[get_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = lambda: mock_db

    with (
        patch("eshopeo.api.routers.content.get_content_draft", new_callable=AsyncMock, return_value=draft),
        patch("eshopeo.api.routers.content.get_product_by_id", new_callable=AsyncMock, return_value=mock_product),
        patch("eshopeo.api.routers.content.approve_content_draft", new_callable=AsyncMock, return_value=approved_draft),
        patch("eshopeo.api.routers.content.write_back_to_platform", new_callable=AsyncMock, return_value=False),
        patch("eshopeo.api.routers.content.get_settings", return_value=MagicMock()),
    ):
        r = client.post(f"/v1/content/products/{product_id}/draft/approve")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["status"] == "approved"


def test_approve_draft_409_if_already_approved():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product_id = uuid4()
    draft = _make_draft(tenant.id, product_id, status="approved")

    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("eshopeo.api.routers.content.get_content_draft", new_callable=AsyncMock, return_value=draft):
        r = client.post(f"/v1/content/products/{product_id}/draft/approve")

    app.dependency_overrides.clear()
    assert r.status_code == 409


def test_approve_draft_404_no_draft():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("eshopeo.api.routers.content.get_content_draft", new_callable=AsyncMock, return_value=None):
        r = client.post(f"/v1/content/products/{uuid4()}/draft/approve")

    app.dependency_overrides.clear()
    assert r.status_code == 404
