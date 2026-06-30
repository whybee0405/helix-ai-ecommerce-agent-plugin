import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from httpx import AsyncClient, ASGITransport

from eshopeo.api.app import create_app
from eshopeo.db.models import Tenant
from tests.conftest import make_test_settings


def make_canonical_order(tenant_id: str, platform_id: str = "wc-1") -> dict:
    return {
        "tenant_id": tenant_id,
        "platform": "woocommerce",
        "platform_id": platform_id,
        "customer_platform_id": None,
        "total_minor": 10000,
        "currency": "ZAR",
        "status": "processing",
        "line_items": [{"product_id": "p1", "quantity": 2}],
        "placed_at": "2026-06-11T10:00:00+00:00",
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


async def test_sync_orders_without_key_returns_401(client):
    resp = await client.post("/v1/sync/orders", json={"orders": []})
    assert resp.status_code == 401


async def test_sync_orders_returns_synced_count(client, tenant):
    orders = [make_canonical_order(str(tenant.id))]

    with patch(
        "eshopeo.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant
    ), patch("eshopeo.api.routers.sync.upsert_order", new_callable=AsyncMock) as mock_upsert:
        mock_upsert.return_value = MagicMock(id=uuid4())

        resp = await client.post(
            "/v1/sync/orders",
            json={"orders": orders},
            headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
        )

    assert resp.status_code == 200
    data = resp.json()
    assert data["synced"] == 1
    assert data["failed"] == 0


async def test_sync_orders_empty_list(client, tenant):
    with patch("eshopeo.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant):
        resp = await client.post(
            "/v1/sync/orders",
            json={"orders": []},
            headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
        )

    assert resp.status_code == 200
    assert resp.json()["synced"] == 0


async def test_sync_orders_handles_upsert_error(client, tenant):
    orders = [make_canonical_order(str(tenant.id))]

    with patch(
        "eshopeo.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant
    ), patch(
        "eshopeo.api.routers.sync.upsert_order",
        new_callable=AsyncMock,
        side_effect=Exception("DB error"),
    ):
        resp = await client.post(
            "/v1/sync/orders",
            json={"orders": orders},
            headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
        )

    assert resp.status_code == 200
    data = resp.json()
    assert data["failed"] == 1
    assert len(data["errors"]) == 1
