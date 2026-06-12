from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def test_customer_segments_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_segments = [
        {"skin_type": "oily", "count": 24},
        {"skin_type": "dry", "count": 18},
    ]

    with patch(
        "helix.api.routers.analytics.get_customer_segments",
        new_callable=AsyncMock,
        return_value=mock_segments,
    ):
        r = client.get("/v1/analytics/customers/segments")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["segments"]) == 2
    assert data["segments"][0]["skin_type"] == "oily"
    assert data["segments"][0]["count"] == 24


def test_customer_segments_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/customers/segments")

    assert r.status_code == 401


def test_customer_segments_empty():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch(
        "helix.api.routers.analytics.get_customer_segments",
        new_callable=AsyncMock,
        return_value=[],
    ):
        r = client.get("/v1/analytics/customers/segments")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["segments"] == []
