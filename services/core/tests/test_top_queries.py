from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def test_top_queries_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_queries = [
        {"query": "best moisturizer for oily skin", "count": 12},
        {"query": "niacinamide with vitamin c", "count": 8},
    ]

    with patch(
        "helix.api.routers.analytics.get_top_queries",
        new_callable=AsyncMock,
        return_value=mock_queries,
    ):
        r = client.get("/v1/analytics/top-queries")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["queries"]) == 2
    assert data["queries"][0]["query"] == "best moisturizer for oily skin"
    assert data["queries"][0]["count"] == 12


def test_top_queries_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/top-queries")

    assert r.status_code == 401


def test_top_queries_empty_list():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch(
        "helix.api.routers.analytics.get_top_queries",
        new_callable=AsyncMock,
        return_value=[],
    ):
        r = client.get("/v1/analytics/top-queries")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["queries"] == []
