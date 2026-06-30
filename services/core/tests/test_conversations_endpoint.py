from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_tenant
from eshopeo.db.models import Conversation, ConversationMessage, Tenant
from tests.conftest import make_test_settings


def _now():
    return datetime.now(timezone.utc)


def test_list_conversations_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    mock_conv = MagicMock(spec=Conversation)
    mock_conv.id = uuid4()
    mock_conv.customer_id = None
    mock_conv.created_at = _now()
    mock_conv.updated_at = _now()

    with patch(
        "eshopeo.api.routers.conversations.list_conversations",
        new_callable=AsyncMock,
        return_value=[mock_conv],
    ):
        r = client.get("/v1/conversations")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert len(data["conversations"]) == 1
    assert data["conversations"][0]["id"] == str(mock_conv.id)


def test_get_conversation_returns_messages():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    conv_id = uuid4()
    mock_conv = MagicMock(spec=Conversation)
    mock_conv.id = conv_id
    mock_conv.tenant_id = tenant.id
    mock_conv.customer_id = None
    mock_conv.created_at = _now()
    mock_conv.updated_at = _now()

    mock_msg = MagicMock(spec=ConversationMessage)
    mock_msg.id = uuid4()
    mock_msg.role = "user"
    mock_msg.content = "What toner?"
    mock_msg.source = None
    mock_msg.feedback = None
    mock_msg.created_at = _now()

    with patch("eshopeo.api.routers.conversations.get_conversation", new_callable=AsyncMock, return_value=mock_conv), \
         patch("eshopeo.api.routers.conversations.get_messages", new_callable=AsyncMock, return_value=[mock_msg]):
        r = client.get(f"/v1/conversations/{conv_id}")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["id"] == str(conv_id)
    assert len(data["messages"]) == 1
    assert data["messages"][0]["content"] == "What toner?"


def test_get_conversation_404_when_not_found():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    with patch("eshopeo.api.routers.conversations.get_conversation", new_callable=AsyncMock, return_value=None):
        r = client.get(f"/v1/conversations/{uuid4()}")

    app.dependency_overrides.clear()

    assert r.status_code == 404


def test_conversations_requires_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.get("/v1/conversations")

    assert r.status_code == 401
