from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Tenant
from tests.conftest import make_test_settings

_SUMMARY = {
    "product_count": 42,
    "customer_count": 10,
    "conversations_this_month": 5,
    "pending_drafts": 3,
    "queries_this_month": 20,
    "cost_this_month_usd": 0.004,
}


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    return t


def test_dashboard_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("helix.api.routers.dashboard.get_dashboard_summary", new_callable=AsyncMock, return_value=_SUMMARY):
        r = client.get("/v1/dashboard")

    app.dependency_overrides.clear()
    assert r.status_code == 200


def test_dashboard_contains_expected_fields():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("helix.api.routers.dashboard.get_dashboard_summary", new_callable=AsyncMock, return_value=_SUMMARY):
        r = client.get("/v1/dashboard")

    app.dependency_overrides.clear()
    body = r.json()
    assert body["product_count"] == 42
    assert body["pending_drafts"] == 3
    assert body["quota_limit"] == settings.default_monthly_query_limit
    assert body["quota_used"] == body["queries_this_month"]


def test_dashboard_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/dashboard")
    assert r.status_code == 401
