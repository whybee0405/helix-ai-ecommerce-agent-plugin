import json
import logging
from enum import Enum
from typing import TypeVar, Type
from uuid import UUID

import anthropic
import structlog
from pydantic import BaseModel, ValidationError

from helix.config import Settings

logger = structlog.get_logger(__name__)

T = TypeVar("T", bound=BaseModel)

_COSTS: dict[str, tuple[float, float]] = {
    "claude-haiku-4-5":  (1.00, 5.00),
    "claude-sonnet-4-6": (3.00, 15.00),
    "claude-opus-4-8":   (5.00, 25.00),
}


class ModelTier(str, Enum):
    CLASSIFY = "classify"
    GENERATE = "generate"
    REASON = "reason"


class LLMParseError(Exception):
    pass


class LLMGateway:
    def __init__(self, settings: Settings, tenant_id: UUID) -> None:
        self._settings = settings
        self._tenant_id = tenant_id
        self._tier_to_model = {
            ModelTier.CLASSIFY: settings.llm_model_classify,
            ModelTier.GENERATE: settings.llm_model_generate,
            ModelTier.REASON:   settings.llm_model_reason,
        }

    async def complete(
        self,
        tier: ModelTier,
        system: str,
        user: str,
        response_schema: Type[T],
        *,
        max_tokens: int = 1024,
    ) -> T:
        model_id = self._tier_to_model[tier]
        client = anthropic.AsyncAnthropic(
            api_key=self._settings.anthropic_api_key.get_secret_value()
        )
        schema_hint = json.dumps(response_schema.model_json_schema(), indent=2)
        user_with_schema = (
            f"{user}\n\nRespond with only valid JSON that matches this schema:\n{schema_hint}"
        )

        message = await client.messages.create(
            model=model_id,
            max_tokens=max_tokens,
            system=[{"type": "text", "text": system, "cache_control": {"type": "ephemeral"}}],
            messages=[{"role": "user", "content": user_with_schema}],
        )

        raw = message.content[0].text
        result = self._parse(raw, response_schema)
        if result is None:
            repair_msg = await client.messages.create(
                model=model_id,
                max_tokens=max_tokens,
                system=[{"type": "text", "text": system, "cache_control": {"type": "ephemeral"}}],
                messages=[
                    {"role": "user", "content": user_with_schema},
                    {"role": "assistant", "content": raw},
                    {
                        "role": "user",
                        "content": (
                            "Your response was not valid JSON. "
                            "Return only the JSON object, nothing else."
                        ),
                    },
                ],
            )
            result = self._parse(repair_msg.content[0].text, response_schema)
            if result is None:
                raise LLMParseError(
                    f"Could not parse LLM response as {response_schema.__name__} "
                    f"after repair attempt. Raw: {repair_msg.content[0].text[:200]}"
                )
            self._log_usage(repair_msg, model_id, "repair")

        self._log_usage(message, model_id, "primary")
        return result

    @staticmethod
    def _parse(text: str, schema: Type[T]) -> T | None:
        try:
            return schema.model_validate(json.loads(text))
        except (json.JSONDecodeError, ValidationError):
            return None

    def _log_usage(self, message: anthropic.types.Message, model_id: str, call_type: str) -> None:
        in_tokens = message.usage.input_tokens
        out_tokens = message.usage.output_tokens
        in_cost, out_cost = _COSTS.get(model_id, (0.0, 0.0))
        cost_usd = (in_tokens * in_cost + out_tokens * out_cost) / 1_000_000
        logger.info(
            "llm_call",
            model=model_id,
            call_type=call_type,
            tokens_in=in_tokens,
            tokens_out=out_tokens,
            cost_usd=round(cost_usd, 6),
            tenant_id=str(self._tenant_id),
        )
