import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from httpx import AsyncClient, ASGITransport

from helix.api.app import create_app
from helix.api.deps import get_widget_tenant
from helix.db.models import Tenant
from helix.api.auth.tokens import issue_widget_token
from helix.domain.routine import build_routine, RoutineResult
from helix.packs.loader import LoadedPack
from tests.conftest import make_test_settings


def make_product_dict(step: str, title: str) -> dict:
    return {
        "id": str(uuid4()),
        "platform_id": str(uuid4()),
        "title": title,
        "price_minor": 25000,
        "currency": "ZAR",
        "domain_attributes": {"step": step, "key_ingredients": []},
    }


def test_build_routine_orders_by_step():
    pack = MagicMock(spec=LoadedPack)
    pack.compatibility_rules = []
    pack.taxonomy = {"routine_steps": ["cleanse", "treat", "moisturize"]}

    products = [
        make_product_dict("moisturize", "Day Cream"),
        make_product_dict("cleanse", "Foam Cleanser"),
        make_product_dict("treat", "Vitamin C Serum"),
    ]
    result = build_routine(products, pack)
    assert result.steps[0]["step"] == "cleanse"
    assert result.steps[1]["step"] == "treat"
    assert result.steps[2]["step"] == "moisturize"


def test_build_routine_identifies_conflicts():
    pack = MagicMock(spec=LoadedPack)
    pack.compatibility_rules = [
        {"id": "r1", "type": "conflict", "description": "Conflict", "ingredients": ["retinol", "glycolic acid"]}
    ]
    pack.taxonomy = {"routine_steps": ["treat"]}

    products = [
        {**make_product_dict("treat", "Retinol Serum"), "domain_attributes": {"step": "treat", "key_ingredients": ["retinol"]}},
        {**make_product_dict("treat", "AHA Toner"), "domain_attributes": {"step": "treat", "key_ingredients": ["glycolic acid"]}},
    ]
    result = build_routine(products, pack)
    assert len(result.conflicts) == 1


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
def app(settings):
    return create_app(settings)


@pytest.fixture
async def client(app):
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        yield c


async def test_routine_without_token_returns_401(client):
    resp = await client.post("/v1/widget/routine", json={"customer_profile": {"skin_type": "dry"}})
    assert resp.status_code == 401


async def test_routine_returns_response(client, app, tenant):
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    with patch("helix.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("helix.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]), \
         patch("helix.api.routers.widget.get_pack_for_tenant") as mock_pack:
        mock_pack.return_value = MagicMock(
            spec=LoadedPack,
            compatibility_rules=[],
            taxonomy={"routine_steps": ["cleanse", "treat", "moisturize"]},
        )

        resp = await client.post(
            "/v1/widget/routine",
            json={"customer_profile": {"skin_type": "dry", "skin_concerns": ["hydration"]}},
        )

    app.dependency_overrides.clear()

    assert resp.status_code == 200
    data = resp.json()
    assert "routine" in data
    assert "missing_steps" in data
    assert "conflicts" in data
