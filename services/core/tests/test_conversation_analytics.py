from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Tenant
from tests.conftest import make_test_settings


def test_conversation_analytics_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_stats = {
        "total_conversations": 5,
        "total_messages": 20,
        "avg_messages_per_conversation": 4.0,
        "feedback_count": 8,
        "feedback_positive_rate": 0.75,
    }

    with patch(
        "eshopeo.api.routers.analytics.get_conversation_analytics",
        new_callable=AsyncMock,
        return_value=mock_stats,
    ):
        r = client.get("/v1/analytics/conversations")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["total_conversations"] == 5
    assert data["feedback_positive_rate"] == 0.75
    assert "period" in data


def test_conversation_analytics_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/analytics/conversations")

    assert r.status_code == 401


def test_conversation_analytics_zero_when_no_data():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_stats = {
        "total_conversations": 0,
        "total_messages": 0,
        "avg_messages_per_conversation": 0.0,
        "feedback_count": 0,
        "feedback_positive_rate": None,
    }

    with patch(
        "eshopeo.api.routers.analytics.get_conversation_analytics",
        new_callable=AsyncMock,
        return_value=mock_stats,
    ):
        r = client.get("/v1/analytics/conversations")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["feedback_positive_rate"] is None
