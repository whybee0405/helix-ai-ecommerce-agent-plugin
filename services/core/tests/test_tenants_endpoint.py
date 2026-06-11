import pytest
from unittest.mock import AsyncMock, patch
from httpx import AsyncClient, ASGITransport
from cryptography.fernet import Fernet

from helix.api.app import create_app
from tests.conftest import make_test_settings


@pytest.fixture
def settings():
    return make_test_settings()


@pytest.fixture
def app(settings):
    return create_app(settings)


@pytest.fixture
async def client(app):
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        yield c


VALID_BODY = {
    "name": "Test Store",
    "platform": "woocommerce",
    "store_url": "https://mystore.co.za",
    "credentials": {"consumer_key": "ck_abc", "consumer_secret": "cs_xyz"},
}


@pytest.mark.asyncio
async def test_provision_without_key_returns_401(client):
    resp = await client.post("/v1/tenants", json=VALID_BODY)
    assert resp.status_code == 401


@pytest.mark.asyncio
async def test_provision_with_wrong_key_returns_401(client, settings):
    resp = await client.post(
        "/v1/tenants",
        json=VALID_BODY,
        headers={"X-Helix-Provision-Key": "wrong-key"},
    )
    assert resp.status_code == 401


@pytest.mark.asyncio
async def test_provision_creates_tenant(client, settings):
    with patch("helix.api.routers.tenants.create_tenant", new_callable=AsyncMock) as mock_create, \
         patch("helix.api.routers.tenants.get_db"):
        from helix.db.models import Tenant
        from uuid import uuid4
        fake_tenant = Tenant(
            id=uuid4(),
            name="Test Store",
            platform="woocommerce",
            store_url="https://mystore.co.za",
            credentials_enc=b"enc",
            public_key=uuid4(),
        )
        mock_create.return_value = fake_tenant

        resp = await client.post(
            "/v1/tenants",
            json=VALID_BODY,
            headers={"X-Helix-Provision-Key": settings.provision_key.get_secret_value()},
        )

    assert resp.status_code == 201
    data = resp.json()
    assert "tenant_id" in data
    assert "public_key" in data
