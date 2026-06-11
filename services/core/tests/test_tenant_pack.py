from helix.db.models import Tenant


def test_tenant_pack_id_defaults_none():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc", pack_id=None)
    assert t.pack_id is None


def test_tenant_pack_id_can_be_set():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc", pack_id="haircare")
    assert t.pack_id == "haircare"


from unittest.mock import AsyncMock, patch
from fastapi.testclient import TestClient
from helix.api.app import create_app
from helix.api.deps import get_db
from tests.conftest import make_test_settings


def test_provision_passes_pack_id_to_tenant():
    settings = make_test_settings()
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)

    captured = {}

    async def mock_create_tenant(session, tenant):
        captured["pack_id"] = tenant.pack_id
        from uuid import uuid4
        from datetime import datetime, timezone
        tenant.id = uuid4()
        tenant.public_key = uuid4()
        tenant.created_at = datetime.now(timezone.utc)
        return tenant

    app.dependency_overrides[get_db] = lambda: mock_db

    with patch("helix.api.routers.tenants.create_tenant", side_effect=mock_create_tenant):
        r = TestClient(app).post(
            "/v1/tenants",
            json={
                "name": "Test", "platform": "woocommerce",
                "store_url": "https://test.com",
                "credentials": {"key": "val"},
                "pack_id": "haircare"
            },
            headers={"X-Helix-Provision-Key": "test-provision-key"},
        )

    assert r.status_code == 201
    assert captured["pack_id"] == "haircare"


def test_get_pack_for_tenant_uses_pack_id():
    from helix.packs.registry import get_pack_for_tenant, _registry
    from helix.db.models import Tenant
    from unittest.mock import MagicMock

    mock_pack = MagicMock()
    mock_pack.id = "haircare"
    _registry["haircare"] = mock_pack

    tenant = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
                    credentials_enc=b"enc", pack_id="haircare")
    result = get_pack_for_tenant(tenant)
    assert result is mock_pack

    del _registry["haircare"]


def test_get_pack_for_tenant_falls_back_when_pack_missing():
    from helix.packs.registry import get_pack_for_tenant, _registry
    from helix.db.models import Tenant
    from unittest.mock import MagicMock

    mock_pack = MagicMock()
    mock_pack.id = "kbeauty"
    _registry.clear()
    _registry["kbeauty"] = mock_pack

    tenant = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
                    credentials_enc=b"enc", pack_id="unknown_pack")
    result = get_pack_for_tenant(tenant)
    assert result is mock_pack
