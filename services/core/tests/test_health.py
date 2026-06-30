import pytest
from httpx import ASGITransport, AsyncClient

from eshopeo.api.app import create_app
from tests.conftest import make_test_settings


@pytest.fixture
def app():
    return create_app(make_test_settings())


@pytest.fixture
async def client(app):
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        yield c


@pytest.mark.asyncio
async def test_health_returns_200(client):
    resp = await client.get("/health")
    assert resp.status_code == 200


@pytest.mark.asyncio
async def test_health_response_shape(client):
    resp = await client.get("/health")
    data = resp.json()
    assert "status" in data
    assert "db" in data
    assert "redis" in data
