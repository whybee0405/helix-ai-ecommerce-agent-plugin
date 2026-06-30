from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Tenant
from tests.conftest import make_test_settings


def test_embedding_coverage_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_coverage = {
        "total": 150,
        "embedded": 148,
        "missing": 2,
        "coverage_rate": 0.99,
    }

    with patch(
        "eshopeo.api.routers.analytics.get_embedding_coverage",
        new_callable=AsyncMock,
        return_value=mock_coverage,
    ):
        r = client.get("/v1/analytics/products/embedding-coverage")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["total"] == 150
    assert data["missing"] == 2
    assert data["coverage_rate"] == 0.99


def test_embedding_coverage_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/products/embedding-coverage")

    assert r.status_code == 401


def test_embedding_coverage_zero_products():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_coverage = {
        "total": 0,
        "embedded": 0,
        "missing": 0,
        "coverage_rate": 1.0,
    }

    with patch(
        "eshopeo.api.routers.analytics.get_embedding_coverage",
        new_callable=AsyncMock,
        return_value=mock_coverage,
    ):
        r = client.get("/v1/analytics/products/embedding-coverage")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["coverage_rate"] == 1.0
