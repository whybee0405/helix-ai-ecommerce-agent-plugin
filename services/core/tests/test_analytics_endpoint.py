import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from httpx import AsyncClient, ASGITransport

from eshopeo.api.app import create_app
from eshopeo.db.models import Tenant
from tests.conftest import make_test_settings


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


async def test_usage_without_key_returns_401(client):
    resp = await client.get("/v1/analytics/usage")
    assert resp.status_code == 401


async def test_usage_returns_summary(client, tenant):
    mock_summary = {
        "total_queries": 42,
        "llm_calls": 42,
        "total_cost_usd": 0.12,
        "by_model": [
            {"model": "claude-haiku-4-5", "calls": 30, "cost_usd": 0.04},
            {"model": "claude-sonnet-4-6", "calls": 12, "cost_usd": 0.08},
        ],
    }

    with patch("eshopeo.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant), \
         patch("eshopeo.api.routers.analytics.get_usage_summary", new_callable=AsyncMock, return_value=mock_summary):

        resp = await client.get(
            "/v1/analytics/usage",
            headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
        )

    assert resp.status_code == 200
    data = resp.json()
    assert data["total_queries"] == 42
    assert data["total_cost_usd"] == 0.12
    assert len(data["by_model"]) == 2
    assert data["period"]["start"] is not None


async def test_usage_with_date_range(client, tenant):
    mock_summary = {
        "total_queries": 10,
        "llm_calls": 10,
        "total_cost_usd": 0.02,
        "by_model": [],
    }

    with patch("eshopeo.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant), \
         patch("eshopeo.api.routers.analytics.get_usage_summary", new_callable=AsyncMock, return_value=mock_summary):

        resp = await client.get(
            "/v1/analytics/usage",
            params={"start_date": "2026-06-01", "end_date": "2026-06-11"},
            headers={"X-eShopeo-Tenant-Key": str(tenant.public_key)},
        )

    assert resp.status_code == 200
    data = resp.json()
    assert data["period"]["start"] == "2026-06-01"
    assert data["period"]["end"] == "2026-06-11"
