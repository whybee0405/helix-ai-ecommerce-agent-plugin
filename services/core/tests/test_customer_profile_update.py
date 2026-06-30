from unittest.mock import AsyncMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db, get_tenant
from eshopeo.db.models import Customer, Tenant
from tests.conftest import make_test_settings


@pytest.fixture
def tenant():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc")
    t.id = uuid4()
    t.public_key = uuid4()
    return t


@pytest.fixture
def customer(tenant):
    c = Customer(
        tenant_id=tenant.id, platform_id="plat-cust-1",
        email_hash="abc", profile={"skin_type": "oily"},
    )
    c.id = uuid4()
    return c


@pytest.fixture
def client(tenant):
    settings = make_test_settings()
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)
    mock_db.commit = AsyncMock()
    app.dependency_overrides[get_db] = lambda: mock_db
    app.dependency_overrides[get_tenant] = lambda: tenant
    return TestClient(app), tenant


def test_patch_profile_merges_and_returns(client, customer):
    c, tenant = client
    updated = Customer(
        tenant_id=tenant.id, platform_id="plat-cust-1",
        email_hash="abc",
        profile={"skin_type": "dry", "concerns": ["acne"]},
    )
    updated.id = customer.id
    with (
        patch("eshopeo.api.routers.sync.get_customer_by_platform_id",
              AsyncMock(return_value=customer)),
        patch("eshopeo.api.routers.sync.update_customer_profile",
              AsyncMock(return_value=updated)) as mock_update,
    ):
        r = c.patch(
            "/v1/sync/customers/plat-cust-1/profile",
            json={"profile": {"skin_type": "dry", "concerns": ["acne"]}},
            headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
        )
    assert r.status_code == 200
    data = r.json()
    assert data["customer_id"] == str(customer.id)
    assert data["platform_id"] == "plat-cust-1"

    # Verify the merged profile was passed to update_customer_profile
    assert mock_update.called
    call_args = mock_update.call_args
    new_profile_passed = call_args.args[2] if len(call_args.args) >= 3 else call_args.kwargs.get("new_profile")
    assert new_profile_passed["skin_type"] == "dry"
    assert new_profile_passed["concerns"] == ["acne"]


def test_patch_profile_404_unknown_customer(client):
    c, tenant = client
    with patch("eshopeo.api.routers.sync.get_customer_by_platform_id",
               AsyncMock(return_value=None)):
        r = c.patch(
            "/v1/sync/customers/unknown-plat/profile",
            json={"profile": {"skin_type": "dry"}},
            headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
        )
    assert r.status_code == 404


def test_patch_profile_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    # Don't override get_tenant for this test - let it enforce auth
    client = TestClient(app)
    r = client.patch(
        "/v1/sync/customers/plat-cust-1/profile",
        json={"profile": {"skin_type": "dry"}},
    )
    assert r.status_code == 401
