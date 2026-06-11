import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from httpx import AsyncClient, ASGITransport

from helix.api.app import create_app
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def make_canonical_product(tenant_id: str, platform_id: str = "42") -> dict:
    return {
        "tenant_id": tenant_id,
        "platform": "woocommerce",
        "platform_id": platform_id,
        "title": "Snail Mucin Essence 96%",
        "price_minor": 34900,
        "currency": "ZAR",
        "images": [],
        "categories": ["Essence"],
        "in_stock": True,
        "domain_attributes": {"skin_types": ["dry", "normal"], "concerns_targeted": ["hydration"]},
    }


@pytest.fixture
def tenant():
    from unittest.mock import MagicMock
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    t.public_key = uuid4()
    t.name = "Test Store"
    t.platform = "woocommerce"
    return t


@pytest.fixture
def app():
    return create_app(make_test_settings())


@pytest.fixture
async def client(app):
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        yield c


@pytest.mark.asyncio
async def test_sync_without_tenant_key_returns_401(client, tenant):
    resp = await client.post("/v1/sync/products", json={"products": []})
    assert resp.status_code == 401


@pytest.mark.asyncio
async def test_sync_valid_products_returns_summary(client, tenant):
    products = [make_canonical_product(str(tenant.id))]

    with patch("helix.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant), \
         patch("helix.api.routers.sync.upsert_product", new_callable=AsyncMock) as mock_upsert, \
         patch("helix.api.routers.sync.embed_product") as mock_task, \
         patch("helix.api.routers.sync.default_pack") as mock_pack:
        mock_pack.return_value = MagicMock(product_schema={"type": "object", "properties": {}, "required": []})
        mock_upsert.return_value = MagicMock(id=uuid4())

        resp = await client.post(
            "/v1/sync/products",
            json={"products": products},
            headers={"X-Helix-Tenant-Key": str(tenant.public_key)},
        )

    assert resp.status_code == 200
    data = resp.json()
    assert data["synced"] == 1
    assert data["failed"] == 0


@pytest.mark.asyncio
async def test_sync_delete_flag_removes_product(client, tenant):
    products = [make_canonical_product(str(tenant.id)) | {"deleted": True}]

    with patch("helix.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant), \
         patch("helix.api.routers.sync.delete_product", new_callable=AsyncMock, return_value=True) as mock_del, \
         patch("helix.api.routers.sync.default_pack") as mock_pack:
        mock_pack.return_value = MagicMock(product_schema={"type": "object", "properties": {}, "required": []})

        resp = await client.post(
            "/v1/sync/products",
            json={"products": products},
            headers={"X-Helix-Tenant-Key": str(tenant.public_key)},
        )

    assert resp.status_code == 200
    mock_del.assert_called_once()
