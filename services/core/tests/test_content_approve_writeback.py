from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_db, get_tenant
from helix.db.models import ContentDraft, Product, Tenant
from tests.conftest import make_test_settings


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    return t


def _make_draft(tenant_id, product_id, status="pending", field="description_html"):
    d = MagicMock(spec=ContentDraft)
    d.product_id = product_id
    d.tenant_id = tenant_id
    d.field = field
    d.draft_text = "<p>Generated</p>"
    d.status = status
    d.created_at = datetime(2026, 6, 12, tzinfo=timezone.utc)
    d.approved_at = None
    return d


def _make_approved_draft(tenant_id, product_id, field="description_html"):
    d = _make_draft(tenant_id, product_id, status="approved", field=field)
    d.approved_at = datetime(2026, 6, 12, 1, 0, tzinfo=timezone.utc)
    return d


def test_approve_description_draft_triggers_writeback():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product_id = uuid4()
    draft = _make_draft(tenant.id, product_id, field="description_html")
    approved_draft = _make_approved_draft(tenant.id, product_id, field="description_html")

    mock_product = MagicMock(spec=Product)
    mock_product.platform_id = "wc-123"
    mock_product.description_html = None

    override_db = AsyncMock()
    override_db.add = MagicMock()
    override_db.commit = AsyncMock()

    app.dependency_overrides[get_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = lambda: override_db

    with (
        patch("helix.api.routers.content.get_content_draft", new_callable=AsyncMock, return_value=draft),
        patch("helix.api.routers.content.get_product_by_id", new_callable=AsyncMock, return_value=mock_product),
        patch("helix.api.routers.content.approve_content_draft", new_callable=AsyncMock, return_value=approved_draft),
        patch("helix.api.routers.content.write_back_to_platform", new_callable=AsyncMock, return_value=True) as mock_wb,
        patch("helix.api.routers.content.get_settings", return_value=MagicMock()),
    ):
        r = client.post(f"/v1/content/products/{product_id}/draft/approve")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["status"] == "approved"
    assert data["platform_synced"] is True
    mock_wb.assert_called_once()


def test_approve_writeback_failure_still_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product_id = uuid4()
    draft = _make_draft(tenant.id, product_id, field="description_html")
    approved_draft = _make_approved_draft(tenant.id, product_id, field="description_html")

    mock_product = MagicMock(spec=Product)
    mock_product.platform_id = "wc-123"
    mock_product.description_html = None

    override_db = AsyncMock()
    override_db.add = MagicMock()
    override_db.commit = AsyncMock()

    app.dependency_overrides[get_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = lambda: override_db

    with (
        patch("helix.api.routers.content.get_content_draft", new_callable=AsyncMock, return_value=draft),
        patch("helix.api.routers.content.get_product_by_id", new_callable=AsyncMock, return_value=mock_product),
        patch("helix.api.routers.content.approve_content_draft", new_callable=AsyncMock, return_value=approved_draft),
        patch("helix.api.routers.content.write_back_to_platform", new_callable=AsyncMock, return_value=False),
        patch("helix.api.routers.content.get_settings", return_value=MagicMock()),
    ):
        r = client.post(f"/v1/content/products/{product_id}/draft/approve")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["platform_synced"] is False


def test_approve_seo_field_skips_writeback():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product_id = uuid4()
    draft = _make_draft(tenant.id, product_id, field="meta_title")
    approved_draft = _make_approved_draft(tenant.id, product_id, field="meta_title")

    override_db = AsyncMock()
    override_db.add = MagicMock()
    override_db.commit = AsyncMock()

    app.dependency_overrides[get_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = lambda: override_db

    with (
        patch("helix.api.routers.content.get_content_draft", new_callable=AsyncMock, return_value=draft),
        patch("helix.api.routers.content.approve_content_draft", new_callable=AsyncMock, return_value=approved_draft),
        patch("helix.api.routers.content.write_back_to_platform", new_callable=AsyncMock) as mock_wb,
    ):
        r = client.post(f"/v1/content/products/{product_id}/draft/approve?field=meta_title")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["platform_synced"] is False
    mock_wb.assert_not_called()
