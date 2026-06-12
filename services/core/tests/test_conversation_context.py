from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

import anthropic
from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_db, get_widget_tenant
from helix.db.models import Tenant
from helix.llm.gateway import ConsultantResponse, LLMGateway, ModelTier
from tests.conftest import make_test_settings


def _make_mock_message(role: str, content: str):
    msg = MagicMock()
    msg.role = role
    msg.content = content
    return msg


def test_context_injected_when_conversation_id_provided():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    mock_db = MagicMock()
    mock_db.commit = AsyncMock()

    conv_id = uuid4()
    mock_conv = MagicMock()
    mock_conv.id = conv_id

    prior_messages = [
        _make_mock_message("user", "Hello"),
        _make_mock_message("assistant", "Hi there"),
    ]

    mock_result = MagicMock()
    mock_result.response = "reply"
    mock_result.source = "llm"
    mock_result.products_referenced = []
    mock_result.cost_usd = 0.0

    mock_user_msg = MagicMock()
    mock_asst_msg = MagicMock()
    mock_asst_msg.id = uuid4()

    async def fake_db():
        yield mock_db

    app.dependency_overrides[get_widget_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = fake_db

    with (
        patch("helix.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024),
        patch("helix.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]),
        patch("helix.api.routers.widget.get_customer_by_id", new_callable=AsyncMock, return_value=None),
        patch("helix.api.routers.widget.get_conversation", new_callable=AsyncMock, return_value=mock_conv),
        patch("helix.api.routers.widget.get_messages", new_callable=AsyncMock, return_value=prior_messages),
        patch("helix.api.routers.widget.handle_query", new_callable=AsyncMock, return_value=mock_result) as mock_handle,
        patch("helix.api.routers.widget.create_usage_event", new_callable=AsyncMock),
        patch("helix.api.routers.widget.append_messages", new_callable=AsyncMock, return_value=(mock_user_msg, mock_asst_msg)),
    ):
        r = client.post(
            "/v1/widget/chat",
            json={"query": "follow-up", "conversation_id": str(conv_id)},
        )

    app.dependency_overrides.clear()

    assert r.status_code == 200
    call_kwargs = mock_handle.call_args.kwargs
    assert call_kwargs["conversation_history"] == [
        {"role": "user", "content": "Hello"},
        {"role": "assistant", "content": "Hi there"},
    ]


def test_context_empty_when_no_conversation_id():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    mock_db = MagicMock()
    mock_db.commit = AsyncMock()

    new_conv = MagicMock()
    new_conv.id = uuid4()

    mock_result = MagicMock()
    mock_result.response = "hello"
    mock_result.source = "template"
    mock_result.products_referenced = []
    mock_result.cost_usd = 0.0

    mock_user_msg = MagicMock()
    mock_asst_msg = MagicMock()
    mock_asst_msg.id = uuid4()

    async def fake_db():
        yield mock_db

    app.dependency_overrides[get_widget_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = fake_db

    with (
        patch("helix.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024),
        patch("helix.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]),
        patch("helix.api.routers.widget.get_customer_by_id", new_callable=AsyncMock, return_value=None),
        patch("helix.api.routers.widget.create_conversation", new_callable=AsyncMock, return_value=new_conv),
        patch("helix.api.routers.widget.get_messages", new_callable=AsyncMock, return_value=[]),
        patch("helix.api.routers.widget.handle_query", new_callable=AsyncMock, return_value=mock_result) as mock_handle,
        patch("helix.api.routers.widget.create_usage_event", new_callable=AsyncMock),
        patch("helix.api.routers.widget.append_messages", new_callable=AsyncMock, return_value=(mock_user_msg, mock_asst_msg)),
    ):
        r = client.post("/v1/widget/chat", json={"query": "hi"})

    app.dependency_overrides.clear()

    assert r.status_code == 200
    call_kwargs = mock_handle.call_args.kwargs
    assert call_kwargs["conversation_history"] == []


def test_context_truncated_to_last_10_messages():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    mock_db = MagicMock()
    mock_db.commit = AsyncMock()

    conv_id = uuid4()
    mock_conv = MagicMock()
    mock_conv.id = conv_id

    many_messages = [
        _make_mock_message("user" if i % 2 == 0 else "assistant", f"msg {i}")
        for i in range(14)
    ]

    mock_result = MagicMock()
    mock_result.response = "reply"
    mock_result.source = "llm"
    mock_result.products_referenced = []
    mock_result.cost_usd = 0.0

    mock_user_msg = MagicMock()
    mock_asst_msg = MagicMock()
    mock_asst_msg.id = uuid4()

    async def fake_db():
        yield mock_db

    app.dependency_overrides[get_widget_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = fake_db

    with (
        patch("helix.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024),
        patch("helix.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]),
        patch("helix.api.routers.widget.get_customer_by_id", new_callable=AsyncMock, return_value=None),
        patch("helix.api.routers.widget.get_conversation", new_callable=AsyncMock, return_value=mock_conv),
        patch("helix.api.routers.widget.get_messages", new_callable=AsyncMock, return_value=many_messages),
        patch("helix.api.routers.widget.handle_query", new_callable=AsyncMock, return_value=mock_result) as mock_handle,
        patch("helix.api.routers.widget.create_usage_event", new_callable=AsyncMock),
        patch("helix.api.routers.widget.append_messages", new_callable=AsyncMock, return_value=(mock_user_msg, mock_asst_msg)),
    ):
        r = client.post(
            "/v1/widget/chat",
            json={"query": "follow-up", "conversation_id": str(conv_id)},
        )

    app.dependency_overrides.clear()

    assert r.status_code == 200
    history = mock_handle.call_args.kwargs["conversation_history"]
    assert len(history) == 10
    assert history[0]["content"] == "msg 4"
    assert history[-1]["content"] == "msg 13"


async def test_gateway_complete_prepends_history():
    settings = make_test_settings()
    gw = LLMGateway(settings=settings, tenant_id=uuid4())

    captured = {}

    async def fake_create(**kwargs):
        captured["messages"] = kwargs["messages"]
        fake_msg = MagicMock(spec=anthropic.types.Message)
        fake_msg.content = [MagicMock(text='{"response": "ok", "product_ids_referenced": []}')]
        fake_msg.usage = MagicMock(input_tokens=10, output_tokens=5)
        return fake_msg

    history = [{"role": "user", "content": "prior question"}]

    with patch("helix.llm.gateway.anthropic.AsyncAnthropic") as mock_cls:
        mock_client = AsyncMock()
        mock_cls.return_value = mock_client
        mock_client.messages.create = fake_create

        await gw.complete(
            tier=ModelTier.GENERATE,
            system="You are helpful",
            user="current question",
            response_schema=ConsultantResponse,
            message_history=history,
        )

    assert len(captured["messages"]) == 2
    assert captured["messages"][0] == {"role": "user", "content": "prior question"}
    assert "current question" in captured["messages"][1]["content"]
