from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import ContentDraft, Tenant
from tests.conftest import make_test_settings


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    return t


def _make_draft(tenant_id, status="pending"):
    d = MagicMock(spec=ContentDraft)
    d.product_id = uuid4()
    d.tenant_id = tenant_id
    d.field = "description_html"
    d.draft_text = "<p>Draft</p>"
    d.status = status
    d.created_at = datetime(2026, 6, 12, tzinfo=timezone.utc)
    d.approved_at = None
    return d


def test_list_drafts_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    drafts = [_make_draft(tenant.id), _make_draft(tenant.id)]
    app.dependency_overrides[get_tenant] = lambda: tenant

    with (
        patch("helix.api.routers.content.list_content_drafts", new_callable=AsyncMock, return_value=drafts),
        patch("helix.api.routers.content.count_content_drafts", new_callable=AsyncMock, return_value=2),
    ):
        r = client.get("/v1/content/drafts")

    app.dependency_overrides.clear()
    assert r.status_code == 200
    body = r.json()
    assert body["total"] == 2
    assert len(body["items"]) == 2


def test_list_drafts_passes_status_filter():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    pending_draft = _make_draft(tenant.id, status="pending")
    mock_list = AsyncMock(return_value=[pending_draft])
    mock_count = AsyncMock(return_value=1)
    app.dependency_overrides[get_tenant] = lambda: tenant

    with (
        patch("helix.api.routers.content.list_content_drafts", mock_list),
        patch("helix.api.routers.content.count_content_drafts", mock_count),
    ):
        r = client.get("/v1/content/drafts?status=pending")

    app.dependency_overrides.clear()
    assert r.status_code == 200
    # Verify status was passed through (as keyword or positional arg)
    call_args = mock_list.call_args
    called_status = call_args.kwargs.get("status") if call_args.kwargs else None
    if called_status is None and call_args.args:
        called_status = call_args.args[2] if len(call_args.args) > 2 else None
    assert called_status == "pending"


def test_list_drafts_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/content/drafts")
    assert r.status_code == 401
