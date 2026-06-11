import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from pydantic import BaseModel

from helix.llm.gateway import LLMGateway, ModelTier, LLMParseError
from tests.conftest import make_test_settings


class CategoryResponse(BaseModel):
    category: str
    confidence: float


@pytest.fixture
def gateway():
    settings = make_test_settings()
    return LLMGateway(settings=settings, tenant_id=uuid4())


@pytest.mark.asyncio
async def test_gateway_returns_parsed_model(gateway):
    mock_message = MagicMock()
    mock_message.content = [MagicMock(text='{"category": "serum", "confidence": 0.95}')]
    mock_message.usage = MagicMock(input_tokens=100, output_tokens=50)

    with patch("helix.llm.gateway.anthropic.AsyncAnthropic") as mock_cls:
        mock_client = AsyncMock()
        mock_cls.return_value = mock_client
        mock_client.messages.create = AsyncMock(return_value=mock_message)

        result = await gateway.complete(
            tier=ModelTier.CLASSIFY,
            system="Classify this product.",
            user="Snail mucin essence",
            response_schema=CategoryResponse,
        )

    assert result.category == "serum"
    assert result.confidence == 0.95


@pytest.mark.asyncio
async def test_gateway_retries_on_parse_failure(gateway):
    mock_first = MagicMock()
    mock_first.content = [MagicMock(text="not valid json")]
    mock_first.usage = MagicMock(input_tokens=50, output_tokens=10)

    mock_repair = MagicMock()
    mock_repair.content = [MagicMock(text='{"category": "toner", "confidence": 0.8}')]
    mock_repair.usage = MagicMock(input_tokens=80, output_tokens=20)

    with patch("helix.llm.gateway.anthropic.AsyncAnthropic") as mock_cls:
        mock_client = AsyncMock()
        mock_cls.return_value = mock_client
        mock_client.messages.create = AsyncMock(side_effect=[mock_first, mock_repair])

        result = await gateway.complete(
            tier=ModelTier.CLASSIFY,
            system="Classify.",
            user="Toner product",
            response_schema=CategoryResponse,
        )

    assert result.category == "toner"
    assert mock_client.messages.create.call_count == 2


@pytest.mark.asyncio
async def test_gateway_raises_after_two_failures(gateway):
    mock_bad = MagicMock()
    mock_bad.content = [MagicMock(text="still not json")]
    mock_bad.usage = MagicMock(input_tokens=50, output_tokens=10)

    with patch("helix.llm.gateway.anthropic.AsyncAnthropic") as mock_cls:
        mock_client = AsyncMock()
        mock_cls.return_value = mock_client
        mock_client.messages.create = AsyncMock(return_value=mock_bad)

        with pytest.raises(LLMParseError):
            await gateway.complete(
                tier=ModelTier.CLASSIFY,
                system="Classify.",
                user="Product",
                response_schema=CategoryResponse,
            )
