from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_widget_tenant
from helix.db.models import Tenant
from helix.llm.gateway import RouteResult
from tests.conftest import make_test_settings


def test_chat_response_includes_conversation_id():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    conv_id = uuid4()
    msg_id = uuid4()

    with patch("helix.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("helix.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]), \
         patch("helix.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle, \
         patch("helix.api.routers.widget.get_pack_for_tenant") as mock_pack, \
         patch("helix.api.routers.widget.create_usage_event", new_callable=AsyncMock), \
         patch("helix.api.routers.widget.create_conversation", new_callable=AsyncMock) as mock_create_conv, \
         patch("helix.api.routers.widget.get_conversation", new_callable=AsyncMock, return_value=None), \
         patch("helix.api.routers.widget.append_messages", new_callable=AsyncMock) as mock_append:
        mock_pack.return_value = MagicMock()
        mock_handle.return_value = RouteResult(response="Use toner", source="template")
        mock_conv = MagicMock()
        mock_conv.id = conv_id
        mock_create_conv.return_value = mock_conv
        mock_asst_msg = MagicMock()
        mock_asst_msg.id = msg_id
        mock_append.return_value = (MagicMock(), mock_asst_msg)

        r = client.post("/v1/widget/chat", json={"query": "what toner?"})

    app.dependency_overrides.clear()

    assert r.status_code == 200
    data = r.json()
    assert data["conversation_id"] == str(conv_id)


def test_chat_response_includes_assistant_message_id():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    msg_id = uuid4()

    with patch("helix.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("helix.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]), \
         patch("helix.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle, \
         patch("helix.api.routers.widget.get_pack_for_tenant") as mock_pack, \
         patch("helix.api.routers.widget.create_usage_event", new_callable=AsyncMock), \
         patch("helix.api.routers.widget.create_conversation", new_callable=AsyncMock) as mock_create_conv, \
         patch("helix.api.routers.widget.get_conversation", new_callable=AsyncMock, return_value=None), \
         patch("helix.api.routers.widget.append_messages", new_callable=AsyncMock) as mock_append:
        mock_pack.return_value = MagicMock()
        mock_handle.return_value = RouteResult(response="Use toner", source="template")
        mock_conv = MagicMock()
        mock_conv.id = uuid4()
        mock_create_conv.return_value = mock_conv
        mock_asst_msg = MagicMock()
        mock_asst_msg.id = msg_id
        mock_append.return_value = (MagicMock(), mock_asst_msg)

        r = client.post("/v1/widget/chat", json={"query": "what toner?"})

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["assistant_message_id"] == str(msg_id)


def test_chat_creates_new_conversation_when_none_provided():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    with patch("helix.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("helix.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]), \
         patch("helix.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle, \
         patch("helix.api.routers.widget.get_pack_for_tenant") as mock_pack, \
         patch("helix.api.routers.widget.create_usage_event", new_callable=AsyncMock), \
         patch("helix.api.routers.widget.create_conversation", new_callable=AsyncMock) as mock_create_conv, \
         patch("helix.api.routers.widget.get_conversation", new_callable=AsyncMock) as mock_get_conv, \
         patch("helix.api.routers.widget.append_messages", new_callable=AsyncMock) as mock_append:
        mock_pack.return_value = MagicMock()
        mock_handle.return_value = RouteResult(response="Use toner", source="template")
        mock_conv = MagicMock()
        mock_conv.id = uuid4()
        mock_create_conv.return_value = mock_conv
        mock_asst = MagicMock()
        mock_asst.id = uuid4()
        mock_append.return_value = (MagicMock(), mock_asst)

        client.post("/v1/widget/chat", json={"query": "hello"})

    app.dependency_overrides.clear()

    mock_get_conv.assert_not_called()
    mock_create_conv.assert_called_once()


def test_chat_appends_to_existing_conversation():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    existing_conv_id = uuid4()

    with patch("helix.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("helix.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]), \
         patch("helix.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle, \
         patch("helix.api.routers.widget.get_pack_for_tenant") as mock_pack, \
         patch("helix.api.routers.widget.create_usage_event", new_callable=AsyncMock), \
         patch("helix.api.routers.widget.create_conversation", new_callable=AsyncMock) as mock_create_conv, \
         patch("helix.api.routers.widget.get_conversation", new_callable=AsyncMock) as mock_get_conv, \
         patch("helix.api.routers.widget.append_messages", new_callable=AsyncMock) as mock_append:
        mock_pack.return_value = MagicMock()
        mock_handle.return_value = RouteResult(response="Use toner", source="template")
        existing_conv = MagicMock()
        existing_conv.id = existing_conv_id
        mock_get_conv.return_value = existing_conv
        mock_asst = MagicMock()
        mock_asst.id = uuid4()
        mock_append.return_value = (MagicMock(), mock_asst)

        client.post("/v1/widget/chat", json={"query": "follow up", "conversation_id": str(existing_conv_id)})

    app.dependency_overrides.clear()

    mock_create_conv.assert_not_called()
    assert mock_append.call_args.kwargs["conversation_id"] == existing_conv_id
