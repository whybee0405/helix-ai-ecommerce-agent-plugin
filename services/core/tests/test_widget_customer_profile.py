from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

import pytest

from eshopeo.api.routers.widget import widget_chat
from eshopeo.db.models import Customer, Tenant


@pytest.fixture
def tenant():
    """Create a test tenant."""
    tenant = Tenant(
        name="Test Tenant",
        platform="shopify",
        store_url="https://test.myshopify.com",
        credentials_enc=b"encrypted_creds",
    )
    tenant.id = uuid4()
    return tenant


@pytest.fixture
def test_customer(tenant):
    """Create a test customer with a profile."""
    customer = Customer(
        tenant_id=tenant.id,
        platform_id="shopify_123",
        email_hash="test_hash",
        profile={"skin_type": "oily", "age_range": "25-35"},
    )
    customer.id = uuid4()
    return customer


class TestWidgetCustomerProfile:
    """Test customer profile merging in widget_chat endpoint."""

    async def test_chat_uses_stored_profile_when_customer_id_provided(
        self, tenant, test_customer
    ):
        """Test that stored customer profile is merged when customer_id is provided."""
        mock_db = AsyncMock()

        with patch(
            "eshopeo.api.routers.widget.embed_query",
            new_callable=AsyncMock,
            return_value=[0.1] * 1024,
        ), patch(
            "eshopeo.api.routers.widget.vector_search_products",
            new_callable=AsyncMock,
            return_value=[],
        ), patch(
            "eshopeo.api.routers.widget.handle_query",
            new_callable=AsyncMock,
        ) as mock_handle, patch(
            "eshopeo.api.routers.widget.create_usage_event",
            new_callable=AsyncMock,
        ), patch(
            "eshopeo.api.routers.widget.get_pack_for_tenant",
            return_value={},
        ), patch(
            "eshopeo.api.routers.widget.get_settings",
        ), patch(
            "eshopeo.api.routers.widget.get_customer_by_id",
            new_callable=AsyncMock,
        ) as mock_get_customer:
            mock_get_customer.return_value = test_customer
            mock_handle.return_value = MagicMock(
                response="ok",
                source="template",
                products_referenced=[],
                cost_usd=0,
                model="test-model",
                tokens_in=10,
                tokens_out=10,
            )

            body = MagicMock(
                query="What's good for oily skin?",
                customer_id=str(test_customer.id),
                customer_profile={},
            )

            result = await widget_chat(body, tenant, mock_db)

            assert result.response == "ok"
            assert mock_get_customer.called
            call_kwargs = mock_handle.call_args.kwargs
            assert call_kwargs["customer_profile"]["skin_type"] == "oily"
            assert call_kwargs["customer_profile"]["age_range"] == "25-35"

    async def test_request_profile_overrides_stored_profile(self, tenant, test_customer):
        """Test that request profile keys override stored profile keys."""
        mock_db = AsyncMock()

        with patch(
            "eshopeo.api.routers.widget.embed_query",
            new_callable=AsyncMock,
            return_value=[0.1] * 1024,
        ), patch(
            "eshopeo.api.routers.widget.vector_search_products",
            new_callable=AsyncMock,
            return_value=[],
        ), patch(
            "eshopeo.api.routers.widget.handle_query",
            new_callable=AsyncMock,
        ) as mock_handle, patch(
            "eshopeo.api.routers.widget.create_usage_event",
            new_callable=AsyncMock,
        ), patch(
            "eshopeo.api.routers.widget.get_pack_for_tenant",
            return_value={},
        ), patch(
            "eshopeo.api.routers.widget.get_settings",
        ), patch(
            "eshopeo.api.routers.widget.get_customer_by_id",
            new_callable=AsyncMock,
        ) as mock_get_customer:
            mock_get_customer.return_value = test_customer
            mock_handle.return_value = MagicMock(
                response="ok",
                source="template",
                products_referenced=[],
                cost_usd=0,
                model="test-model",
                tokens_in=10,
                tokens_out=10,
            )

            body = MagicMock(
                query="What's good for dry skin?",
                customer_id=str(test_customer.id),
                customer_profile={"skin_type": "dry"},
            )

            result = await widget_chat(body, tenant, mock_db)

            assert result.response == "ok"
            call_kwargs = mock_handle.call_args.kwargs
            # Request skin_type should override stored skin_type
            assert call_kwargs["customer_profile"]["skin_type"] == "dry"
            # Stored age_range should still be present
            assert call_kwargs["customer_profile"]["age_range"] == "25-35"

    async def test_chat_works_without_customer_id(self, tenant):
        """Test that chat works without customer_id and get_customer_by_id is not called."""
        mock_db = AsyncMock()

        with patch(
            "eshopeo.api.routers.widget.embed_query",
            new_callable=AsyncMock,
            return_value=[0.1] * 1024,
        ), patch(
            "eshopeo.api.routers.widget.vector_search_products",
            new_callable=AsyncMock,
            return_value=[],
        ), patch(
            "eshopeo.api.routers.widget.handle_query",
            new_callable=AsyncMock,
        ) as mock_handle, patch(
            "eshopeo.api.routers.widget.create_usage_event",
            new_callable=AsyncMock,
        ), patch(
            "eshopeo.api.routers.widget.get_pack_for_tenant",
            return_value={},
        ), patch(
            "eshopeo.api.routers.widget.get_settings",
        ), patch(
            "eshopeo.api.routers.widget.get_customer_by_id",
            new_callable=AsyncMock,
        ) as mock_get_customer:
            mock_handle.return_value = MagicMock(
                response="ok",
                source="template",
                products_referenced=[],
                cost_usd=0,
                model="test-model",
                tokens_in=10,
                tokens_out=10,
            )

            body = MagicMock(
                query="What's good for my skin?",
                customer_id=None,
                customer_profile={"skin_type": "combination"},
            )

            result = await widget_chat(body, tenant, mock_db)

            assert result.response == "ok"
            # get_customer_by_id should NOT be called
            assert not mock_get_customer.called
            # handle_query should be called with the request profile
            call_kwargs = mock_handle.call_args.kwargs
            assert call_kwargs["customer_profile"]["skin_type"] == "combination"

    async def test_chat_ignores_invalid_customer_id(self, tenant):
        """Test that invalid customer_id is silently ignored without crashing."""
        mock_db = AsyncMock()

        with patch(
            "eshopeo.api.routers.widget.embed_query",
            new_callable=AsyncMock,
            return_value=[0.1] * 1024,
        ), patch(
            "eshopeo.api.routers.widget.vector_search_products",
            new_callable=AsyncMock,
            return_value=[],
        ), patch(
            "eshopeo.api.routers.widget.handle_query",
            new_callable=AsyncMock,
        ) as mock_handle, patch(
            "eshopeo.api.routers.widget.create_usage_event",
            new_callable=AsyncMock,
        ), patch(
            "eshopeo.api.routers.widget.get_pack_for_tenant",
            return_value={},
        ), patch(
            "eshopeo.api.routers.widget.get_settings",
        ), patch(
            "eshopeo.api.routers.widget.get_customer_by_id",
            new_callable=AsyncMock,
        ) as mock_get_customer:
            mock_handle.return_value = MagicMock(
                response="ok",
                source="template",
                products_referenced=[],
                cost_usd=0,
                model="test-model",
                tokens_in=10,
                tokens_out=10,
            )

            body = MagicMock(
                query="Test query",
                customer_id="not-a-uuid",
                customer_profile={"skin_type": "oily"},
            )

            result = await widget_chat(body, tenant, mock_db)

            assert result.response == "ok"
            # get_customer_by_id should NOT be called due to invalid UUID
            assert not mock_get_customer.called
            # handle_query should be called with request profile (not merged)
            call_kwargs = mock_handle.call_args.kwargs
            assert call_kwargs["customer_profile"]["skin_type"] == "oily"
