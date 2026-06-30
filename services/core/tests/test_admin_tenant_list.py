from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.routers.admin import _auth_provision
from eshopeo.db.models import Tenant
from tests.conftest import make_test_settings


def _make_mock_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    t.name = "Test Store"
    t.platform = "shopify"
    t.store_url = "https://test.myshopify.com"
    t.public_key = uuid4()
    t.pack_id = "kbeauty"
    t.created_at = datetime(2026, 6, 1, tzinfo=timezone.utc)
    return t


def test_admin_tenant_list_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    app.dependency_overrides[_auth_provision] = lambda: "test-key"

    mock_tenants = [_make_mock_tenant(), _make_mock_tenant()]

    with (
        patch(
            "eshopeo.api.routers.admin.list_tenants",
            new_callable=AsyncMock,
            return_value=mock_tenants,
        ),
        patch(
            "eshopeo.api.routers.admin.count_tenants",
            new_callable=AsyncMock,
            return_value=2,
        ),
    ):
        r = client.get("/v1/admin/tenants")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["tenants"]) == 2
    assert data["total"] == 2
    assert "credentials_enc" not in data["tenants"][0]


def test_admin_tenant_detail_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    app.dependency_overrides[_auth_provision] = lambda: "test-key"

    mock_tenant = _make_mock_tenant()
    tenant_id = mock_tenant.id

    with patch(
        "eshopeo.api.routers.admin.get_tenant_by_id",
        new_callable=AsyncMock,
        return_value=mock_tenant,
    ):
        r = client.get(f"/v1/admin/tenants/{tenant_id}")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["id"] == str(tenant_id)
    assert "credentials_enc" not in r.json()


def test_admin_tenant_detail_404_on_unknown():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    app.dependency_overrides[_auth_provision] = lambda: "test-key"

    with patch(
        "eshopeo.api.routers.admin.get_tenant_by_id",
        new_callable=AsyncMock,
        return_value=None,
    ):
        r = client.get(f"/v1/admin/tenants/{uuid4()}")

    app.dependency_overrides.clear()

    assert r.status_code == 404
