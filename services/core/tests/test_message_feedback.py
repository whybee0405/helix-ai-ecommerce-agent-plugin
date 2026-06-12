from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_widget_tenant
from helix.db.models import ConversationMessage, Tenant
from tests.conftest import make_test_settings


def test_feedback_accepted_thumbs_up():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    message_id = uuid4()
    mock_msg = MagicMock(spec=ConversationMessage)
    mock_msg.role = "assistant"
    mock_msg.feedback = "thumbs_up"

    with patch("helix.api.routers.widget.set_message_feedback", new_callable=AsyncMock, return_value=mock_msg):
        r = client.post(
            f"/v1/widget/conversations/{message_id}/feedback",
            json={"feedback": "thumbs_up"},
        )

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["message_id"] == str(message_id)
    assert data["feedback"] == "thumbs_up"


def test_feedback_accepted_thumbs_down():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    message_id = uuid4()
    mock_msg = MagicMock(spec=ConversationMessage)
    mock_msg.role = "assistant"
    mock_msg.feedback = "thumbs_down"

    with patch("helix.api.routers.widget.set_message_feedback", new_callable=AsyncMock, return_value=mock_msg):
        r = client.post(
            f"/v1/widget/conversations/{message_id}/feedback",
            json={"feedback": "thumbs_down"},
        )

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["message_id"] == str(message_id)
    assert data["feedback"] == "thumbs_down"


def test_feedback_404_on_unknown_message():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    message_id = uuid4()
    with patch("helix.api.routers.widget.set_message_feedback", new_callable=AsyncMock, return_value=None):
        r = client.post(
            f"/v1/widget/conversations/{message_id}/feedback",
            json={"feedback": "thumbs_down"},
        )

    app.dependency_overrides.clear()

    assert r.status_code == 404


def test_feedback_requires_widget_auth():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    r = client.post(
        f"/v1/widget/conversations/{uuid4()}/feedback",
        json={"feedback": "thumbs_up"},
    )

    assert r.status_code == 401
