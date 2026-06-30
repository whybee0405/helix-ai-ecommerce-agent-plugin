import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from httpx import AsyncClient, ASGITransport

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_widget_tenant
from eshopeo.db.models import Tenant
from eshopeo.api.auth.tokens import issue_widget_token
from eshopeo.llm.gateway import RouteResult
from tests.conftest import make_test_settings


@pytest.fixture
def settings():
    return make_test_settings()


@pytest.fixture
def tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    t.public_key = uuid4()
    return t


@pytest.fixture
def jwt_token(tenant, settings):
    return issue_widget_token(tenant.id, settings.session_secret.get_secret_value())


@pytest.fixture
def app(settings):
    return create_app(settings)


@pytest.fixture
async def client(app):
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        yield c


async def test_chat_without_token_returns_401(client):
    resp = await client.post("/v1/widget/chat", json={"query": "hi"})
    assert resp.status_code == 401


async def test_chat_with_valid_token_returns_response(client, app, tenant):
    # Override the dependency so we skip the real JWT/DB lookup
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    with patch("eshopeo.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("eshopeo.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]), \
         patch("eshopeo.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle, \
         patch("eshopeo.api.routers.widget.get_pack_for_tenant") as mock_pack:
        mock_pack.return_value = MagicMock()
        mock_handle.return_value = RouteResult(
            response="For dry skin I recommend the Snail Essence.",
            source="llm",
            products_referenced=["42"],
        )

        resp = await client.post(
            "/v1/widget/chat",
            json={"query": "What helps dry skin?", "customer_profile": {"skin_type": "dry"}},
        )

    # Clean up override
    app.dependency_overrides.clear()

    assert resp.status_code == 200
    data = resp.json()
    assert "response" in data
    assert data["source"] == "llm"
