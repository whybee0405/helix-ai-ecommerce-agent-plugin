import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from eshopeo.llm.gateway import LLMGateway, ModelTier, QueryIntent, RouteResult
from tests.conftest import make_test_settings


@pytest.fixture
def gateway():
    return LLMGateway(settings=make_test_settings(), tenant_id=uuid4())


async def test_classify_intent_returns_intent(gateway):
    mock_message = MagicMock()
    mock_message.content = [MagicMock(text='{"intent": "product_search", "confidence": 0.95}')]
    mock_message.usage = MagicMock(input_tokens=50, output_tokens=20)

    with patch("eshopeo.llm.gateway.anthropic.AsyncAnthropic") as mock_cls:
        mock_client = AsyncMock()
        mock_cls.return_value = mock_client
        mock_client.messages.create = AsyncMock(return_value=mock_message)

        result = await gateway.classify_intent("What serum is good for dry skin?")

    assert result.intent == "product_search"
    assert result.confidence == 0.95


async def test_route_query_uses_template_layer_first(gateway):
    from eshopeo.llm.layers import LayerResult

    with patch("eshopeo.llm.gateway.anthropic.AsyncAnthropic"), \
         patch("eshopeo.llm.layers.TemplateLayer.query", new_callable=AsyncMock) as mock_template, \
         patch.object(gateway, "classify_intent", new_callable=AsyncMock) as mock_intent:

        mock_intent.return_value = QueryIntent(intent="faq", confidence=0.9)
        mock_template.return_value = LayerResult(answered=True, response="Returns within 30 days.")

        result = await gateway.route_query(
            query="What is your return policy?",
            system_prompt="You are an advisor.",
            context_products=[],
            customer_profile={},
            pack_rules=[],
            pack_templates={"return_policy": "Returns within 30 days."},
        )

    assert result.source == "template"
    assert "30 days" in result.response
