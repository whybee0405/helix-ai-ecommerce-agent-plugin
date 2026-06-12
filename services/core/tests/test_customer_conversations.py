from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_tenant
from helix.db.models import Conversation, Customer, Tenant
from tests.conftest import make_test_settings


def _make_mock_conversation(customer_id):
    c = MagicMock(spec=Conversation)
    c.id = uuid4()
    c.customer_id = customer_id
    c.created_at = datetime(2026, 6, 1, tzinfo=timezone.utc)
    c.updated_at = datetime(2026, 6, 1, tzinfo=timezone.utc)
    return c


def test_customer_conversations_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    customer_id = uuid4()
    mock_customer = MagicMock(spec=Customer)
    mock_customer.id = customer_id

    mock_convs = [
        _make_mock_conversation(customer_id),
        _make_mock_conversation(customer_id),
    ]

    with (
        patch(
            "helix.api.routers.customers.get_customer_by_id",
            new_callable=AsyncMock,
            return_value=mock_customer,
        ),
        patch(
            "helix.api.routers.customers.list_conversations_by_customer",
            new_callable=AsyncMock,
            return_value=mock_convs,
        ),
    ):
        r = client.get(f"/v1/customers/{customer_id}/conversations")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert len(r.json()["conversations"]) == 2


def test_customer_conversations_404_on_unknown_customer():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    customer_id = uuid4()

    with patch(
        "helix.api.routers.customers.get_customer_by_id",
        new_callable=AsyncMock,
        return_value=None,
    ):
        r = client.get(f"/v1/customers/{customer_id}/conversations")

    app.dependency_overrides.clear()

    assert r.status_code == 404


def test_customer_conversations_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get(f"/v1/customers/{uuid4()}/conversations")

    assert r.status_code == 401
