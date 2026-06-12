import json
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_widget_tenant
from helix.db.models import Tenant
from helix.llm.gateway import RouteResult
from tests.conftest import make_test_settings


def _parse_events(text: str) -> list[dict]:
    """Parse SSE events from response text."""
    lines = [l for l in text.split("\n") if l.startswith("data: ")]
    return [json.loads(l[6:]) for l in lines]


def test_chat_stream_returns_event_stream_content_type():
    """Test that streaming chat returns text/event-stream content type."""
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    tenant.public_key = uuid4()

    # Override the dependency
    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    with patch("helix.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("helix.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]), \
         patch("helix.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle, \
         patch("helix.api.routers.widget.get_pack_for_tenant") as mock_pack, \
         patch("helix.api.routers.widget.create_usage_event", new_callable=AsyncMock):
        mock_pack.return_value = MagicMock()
        mock_handle.return_value = RouteResult(
            response="We ship worldwide",
            source="template",
        )

        r = client.post(
            "/v1/widget/chat/stream",
            json={"query": "shipping?"},
        )

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert "text/event-stream" in r.headers["content-type"]


def test_chat_stream_contains_token_and_done_events():
    """Test that streaming response contains token and done events."""
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    tenant.public_key = uuid4()

    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    with patch("helix.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("helix.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]), \
         patch("helix.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle, \
         patch("helix.api.routers.widget.get_pack_for_tenant") as mock_pack, \
         patch("helix.api.routers.widget.create_usage_event", new_callable=AsyncMock):
        mock_pack.return_value = MagicMock()
        mock_handle.return_value = RouteResult(
            response="We ship worldwide",
            source="template",
        )

        r = client.post(
            "/v1/widget/chat/stream",
            json={"query": "shipping?"},
        )

    app.dependency_overrides.clear()

    assert r.status_code == 200
    events = _parse_events(r.text)

    # Check that we have token and done events
    types = [e.get("type") for e in events]
    assert "token" in types
    assert "done" in types

    # Find token event and check content
    token_events = [e for e in events if e.get("type") == "token"]
    assert len(token_events) > 0
    assert token_events[0]["content"] == "We ship worldwide"


def test_chat_stream_passes_source_in_done_event():
    """Test that done event contains source from handle_query."""
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    tenant.public_key = uuid4()

    app.dependency_overrides[get_widget_tenant] = lambda: tenant

    with patch("helix.api.routers.widget.embed_query", new_callable=AsyncMock, return_value=[0.1] * 1024), \
         patch("helix.api.routers.widget.vector_search_products", new_callable=AsyncMock, return_value=[]), \
         patch("helix.api.routers.widget.handle_query", new_callable=AsyncMock) as mock_handle, \
         patch("helix.api.routers.widget.get_pack_for_tenant") as mock_pack, \
         patch("helix.api.routers.widget.create_usage_event", new_callable=AsyncMock) as mock_usage:
        mock_pack.return_value = MagicMock()
        mock_handle.return_value = RouteResult(
            response="Use retinol",
            source="llm",
            cost_usd=0.001,
            model="claude-sonnet-4-6",
            tokens_in=50,
            tokens_out=20,
        )

        r = client.post(
            "/v1/widget/chat/stream",
            json={"query": "anti-aging?"},
        )

    app.dependency_overrides.clear()

    assert r.status_code == 200
    events = _parse_events(r.text)

    # Find done event and check source
    done_events = [e for e in events if e.get("type") == "done"]
    assert len(done_events) > 0
    assert done_events[0]["source"] == "llm"

    # Verify create_usage_event was called because cost_usd > 0
    assert mock_usage.called


def test_chat_stream_requires_auth():
    """Test that streaming chat requires authentication."""
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    # Do NOT override the dependency, so real auth check runs
    r = client.post(
        "/v1/widget/chat/stream",
        json={"query": "test"},
    )

    assert r.status_code == 401
