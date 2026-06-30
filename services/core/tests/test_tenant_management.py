from datetime import datetime, timezone
from unittest.mock import AsyncMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db
from eshopeo.db.models import Tenant
from tests.conftest import make_test_settings


@pytest.fixture
def tenant():
    t = Tenant(name="Seoul Beauty", platform="woocommerce",
               store_url="https://sb.com", credentials_enc=b"enc", pack_id="kbeauty")
    t.id = uuid4()
    t.public_key = uuid4()
    t.created_at = datetime.now(timezone.utc)
    return t


@pytest.fixture
def client(tenant):
    settings = make_test_settings()
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)

    async def _get_by_id(session, tid):
        return tenant if str(tid) == str(tenant.id) else None

    async def _update(session, t, **fields):
        for k, v in fields.items():
            setattr(t, k, v)
        return t

    app.dependency_overrides[get_db] = lambda: mock_db

    with (
        patch("eshopeo.api.routers.tenants.get_tenant_by_id", side_effect=_get_by_id),
        patch("eshopeo.api.routers.tenants.update_tenant", side_effect=_update),
    ):
        yield TestClient(app), tenant, settings


def test_get_tenant_returns_details(client):
    c, tenant, settings = client
    r = c.get(
        f"/v1/tenants/{tenant.id}",
        headers={"X-eShopeo-Provision-Key": settings.provision_key.get_secret_value()},
    )
    assert r.status_code == 200
    data = r.json()
    assert data["tenant_id"] == str(tenant.id)
    assert data["name"] == "Seoul Beauty"
    assert data["pack_id"] == "kbeauty"
    assert "credentials_enc" not in data


def test_get_tenant_401_bad_key(client):
    c, tenant, _ = client
    r = c.get(f"/v1/tenants/{tenant.id}", headers={"X-eShopeo-Provision-Key": "wrong"})
    assert r.status_code == 401


def test_get_tenant_404_unknown(client):
    c, _, settings = client
    r = c.get(
        f"/v1/tenants/{uuid4()}",
        headers={"X-eShopeo-Provision-Key": settings.provision_key.get_secret_value()},
    )
    assert r.status_code == 404


def test_patch_tenant_updates_pack_id(client):
    c, tenant, settings = client
    r = c.patch(
        f"/v1/tenants/{tenant.id}",
        json={"pack_id": "haircare"},
        headers={"X-eShopeo-Provision-Key": settings.provision_key.get_secret_value()},
    )
    assert r.status_code == 200
    assert r.json()["pack_id"] == "haircare"


def test_patch_tenant_updates_name(client):
    c, tenant, settings = client
    r = c.patch(
        f"/v1/tenants/{tenant.id}",
        json={"name": "New Name"},
        headers={"X-eShopeo-Provision-Key": settings.provision_key.get_secret_value()},
    )
    assert r.status_code == 200
    assert r.json()["name"] == "New Name"
