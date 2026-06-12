from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def test_suggest_returns_matching_titles():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch(
        "helix.api.routers.search.suggest_product_titles",
        new_callable=AsyncMock,
        return_value=["Toner A", "Toner B"],
    ):
        r = client.get("/v1/search/suggest", params={"q": "ton"})

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["suggestions"] == ["Toner A", "Toner B"]
    assert data["prefix"] == "ton"


def test_suggest_empty_results_ok():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch(
        "helix.api.routers.search.suggest_product_titles",
        new_callable=AsyncMock,
        return_value=[],
    ):
        r = client.get("/v1/search/suggest", params={"q": "xyz"})

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["suggestions"] == []
    assert data["prefix"] == "xyz"


def test_suggest_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    # No dependency override — real auth runs
    r = client.get("/v1/search/suggest", params={"q": "ton"})

    assert r.status_code == 401
