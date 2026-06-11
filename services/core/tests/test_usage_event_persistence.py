import inspect
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

import pytest

from helix.db.crud.usage_events import create_usage_event
from helix.llm.gateway import RouteResult


class TestRouteResultExtension:
    """Test that RouteResult includes cost fields."""

    def test_route_result_has_cost_fields(self) -> None:
        """RouteResult should accept and store model, tokens_in, tokens_out, cost_usd."""
        result = RouteResult(
            response="test response",
            source="llm",
            products_referenced=["prod1"],
            model="claude-sonnet-4-6",
            tokens_in=100,
            tokens_out=50,
            cost_usd=0.001,
        )
        assert result.response == "test response"
        assert result.source == "llm"
        assert result.products_referenced == ["prod1"]
        assert result.model == "claude-sonnet-4-6"
        assert result.tokens_in == 100
        assert result.tokens_out == 50
        assert result.cost_usd == 0.001

    def test_route_result_defaults_to_zero_cost(self) -> None:
        """RouteResult should default cost fields to 0 / empty."""
        result = RouteResult(response="test", source="template")
        assert result.model == ""
        assert result.tokens_in == 0
        assert result.tokens_out == 0
        assert result.cost_usd == 0.0


class TestWidgetChatUsageEvent:
    """Test that widget_chat creates UsageEvent when LLM is called."""

    async def test_widget_chat_creates_usage_event_when_llm_called(self) -> None:
        """When handle_query returns cost_usd > 0, create_usage_event should be called."""
        tenant_id = uuid4()

        # Mock RouteResult with cost
        route_result = RouteResult(
            response="test response",
            source="llm",
            model="claude-sonnet-4-6",
            tokens_in=100,
            tokens_out=50,
            cost_usd=0.001,
        )

        mock_db = AsyncMock()

        with patch(
            "helix.api.routers.widget.handle_query",
            new_callable=AsyncMock,
            return_value=route_result,
        ) as mock_handle_query, patch(
            "helix.api.routers.widget.create_usage_event",
            new_callable=AsyncMock,
        ) as mock_create_usage_event, patch(
            "helix.api.routers.widget.embed_query",
            new_callable=AsyncMock,
            return_value=[1.0] * 100,
        ), patch(
            "helix.api.routers.widget.vector_search_products",
            new_callable=AsyncMock,
            return_value=[],
        ), patch(
            "helix.api.routers.widget.get_pack_for_tenant",
            return_value={},
        ), patch(
            "helix.api.routers.widget.get_settings",
        ):
            from helix.api.routers.widget import widget_chat
            from helix.db.models import Tenant

            tenant = Tenant(
                name="test",
                platform="shopify",
                store_url="https://test.com",
                credentials_enc=b"test",
            )
            tenant.id = tenant_id

            body = MagicMock(query="test query", customer_profile={})

            result = await widget_chat(body, tenant, mock_db)

            # Verify create_usage_event was called with correct params
            mock_create_usage_event.assert_called_once()
            call_args = mock_create_usage_event.call_args
            assert call_args[0][0] == mock_db  # session
            assert call_args[0][1] == tenant_id  # tenant_id
            assert call_args[0][2] == "claude-sonnet-4-6"  # model
            assert call_args[0][3] == 100  # tokens_in
            assert call_args[0][4] == 50  # tokens_out
            assert call_args[0][5] == 0.001  # cost_usd
            assert call_args[0][6] == "/v1/widget/chat"  # endpoint

            # Verify db.commit was called
            mock_db.commit.assert_called_once()

    async def test_widget_chat_skips_usage_event_when_template_hit(self) -> None:
        """When handle_query returns cost_usd = 0 (template/rules hit), skip create_usage_event."""
        tenant_id = uuid4()

        # Mock RouteResult without cost (template hit)
        route_result = RouteResult(
            response="test response",
            source="template",
            cost_usd=0.0,
        )

        mock_db = AsyncMock()

        with patch(
            "helix.api.routers.widget.handle_query",
            new_callable=AsyncMock,
            return_value=route_result,
        ), patch(
            "helix.api.routers.widget.create_usage_event",
            new_callable=AsyncMock,
        ) as mock_create_usage_event, patch(
            "helix.api.routers.widget.embed_query",
            new_callable=AsyncMock,
            return_value=[1.0] * 100,
        ), patch(
            "helix.api.routers.widget.vector_search_products",
            new_callable=AsyncMock,
            return_value=[],
        ), patch(
            "helix.api.routers.widget.get_pack_for_tenant",
            return_value={},
        ), patch(
            "helix.api.routers.widget.get_settings",
        ):
            from helix.api.routers.widget import widget_chat
            from helix.db.models import Tenant

            tenant = Tenant(
                name="test",
                platform="shopify",
                store_url="https://test.com",
                credentials_enc=b"test",
            )
            tenant.id = tenant_id

            body = MagicMock(query="test query", customer_profile={})

            result = await widget_chat(body, tenant, mock_db)

            # create_usage_event should NOT be called
            mock_create_usage_event.assert_not_called()


class TestWidgetRoutineUsageEvent:
    """Test that widget_routine commits the database."""

    async def test_widget_routine_commits_db(self) -> None:
        """widget_routine should call db.commit after build_routine."""
        tenant_id = uuid4()

        mock_db = AsyncMock()

        with patch(
            "helix.api.routers.widget.embed_query",
            new_callable=AsyncMock,
            return_value=[1.0] * 100,
        ), patch(
            "helix.api.routers.widget.vector_search_products",
            new_callable=AsyncMock,
            return_value=[],
        ), patch(
            "helix.api.routers.widget.build_routine",
            return_value=MagicMock(
                steps=[], conflicts=[], cautions=[], missing_steps=[], llm_augmented=False
            ),
        ), patch(
            "helix.api.routers.widget.get_pack_for_tenant",
            return_value={},
        ), patch(
            "helix.api.routers.widget.get_settings",
        ):
            from helix.api.routers.widget import widget_routine
            from helix.db.models import Tenant

            tenant = Tenant(
                name="test",
                platform="shopify",
                store_url="https://test.com",
                credentials_enc=b"test",
            )
            tenant.id = tenant_id

            body = MagicMock(customer_profile={"skin_type": "oily", "skin_concerns": []}, budget_minor=None)

            result = await widget_routine(body, tenant, mock_db)

            # Verify db.commit was called
            mock_db.commit.assert_called_once()
            assert result.routine == []
            assert result.conflicts == []


class TestCreateUsageEventCRUD:
    """Test create_usage_event function signature and behavior."""

    def test_create_usage_event_crud_has_correct_params(self) -> None:
        """create_usage_event should have correct parameters."""
        sig = inspect.signature(create_usage_event)
        params = list(sig.parameters.keys())

        # Check required parameters
        assert "session" in params
        assert "tenant_id" in params
        assert "model" in params
        assert "tokens_in" in params
        assert "tokens_out" in params
        assert "cost_usd" in params
        assert "endpoint" in params

    async def test_create_usage_event_writes_to_db(self) -> None:
        """create_usage_event should create and flush UsageEvent to session."""
        tenant_id = uuid4()

        mock_session = AsyncMock()

        result = await create_usage_event(
            session=mock_session,
            tenant_id=tenant_id,
            model="claude-sonnet-4-6",
            tokens_in=100,
            tokens_out=50,
            cost_usd=0.001,
            endpoint="/v1/widget/chat",
        )

        # Verify UsageEvent was added and flushed
        mock_session.add.assert_called_once()
        mock_session.flush.assert_called_once()

        # Verify returned object is UsageEvent with correct fields
        assert result.tenant_id == tenant_id
        assert result.model == "claude-sonnet-4-6"
        assert result.tokens_in == 100
        assert result.tokens_out == 50
        assert result.cost_usd == 0.001
        assert result.endpoint == "/v1/widget/chat"
