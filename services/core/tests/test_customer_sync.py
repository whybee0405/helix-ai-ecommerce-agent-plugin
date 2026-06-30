import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from httpx import AsyncClient, ASGITransport

from eshopeo.api.app import create_app
from eshopeo.db.models import Customer, Tenant
from tests.conftest import make_test_settings


def make_canonical_customer(tenant_id: str, platform_id: str = "cust-1") -> dict:
    return {
        "tenant_id": tenant_id,
        "platform": "woocommerce",
        "platform_id": platform_id,
        "email_hash": "abc123",
        "profile": {"skin_type": "dry"},
    }


@pytest.fixture
def tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    t.public_key = uuid4()
    return t


@pytest.fixture
def app():
    return create_app(make_test_settings())


@pytest.fixture
async def client(app):
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        yield c


async def test_customer_sync_without_key_returns_401(client):
    resp = await client.post("/v1/sync/customers", json={"customers": []})
    assert resp.status_code == 401


async def test_customer_sync_valid(client, tenant):
    customers = [make_canonical_customer(str(tenant.id))]

    with patch("eshopeo.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant), \
         patch("eshopeo.api.routers.sync.upsert_customer", new_callable=AsyncMock) as mock_upsert, \
         patch("eshopeo.api.routers.sync.get_pack_for_tenant") as mock_pack:
        mock_pack.return_value = MagicMock(profile_schema={
            "type": "object",
            "properties": {"skin_type": {"type": "string"}},
            "required": ["skin_type"],
        })
        mock_upsert.return_value = MagicMock(id=uuid4())

        resp = await client.post(
            "/v1/sync/customers",
            json={"customers": customers},
            headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
        )

    assert resp.status_code == 200
    data = resp.json()
    assert data["synced"] == 1
    assert data["failed"] == 0


async def test_customer_sync_invalid_profile_fails(client, tenant):
    customers = [
        {
            "tenant_id": str(tenant.id),
            "platform": "woocommerce",
            "platform_id": "cust-1",
            "email_hash": "abc",
            "profile": {"skin_type": "invalid_enum_value"},
        }
    ]

    with patch("eshopeo.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant), \
         patch("eshopeo.api.routers.sync.get_pack_for_tenant") as mock_pack:
        mock_pack.return_value = MagicMock(profile_schema={
            "type": "object",
            "properties": {
                "skin_type": {"type": "string", "enum": ["dry", "oily", "normal"]}
            },
            "required": ["skin_type"],
        })

        resp = await client.post(
            "/v1/sync/customers",
            json={"customers": customers},
            headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
        )

    assert resp.status_code == 200
    data = resp.json()
    assert data["failed"] == 1
    assert len(data["errors"]) == 1
