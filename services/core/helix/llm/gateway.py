import json
import logging
from enum import Enum
from typing import TypeVar, Type, Literal
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


class QueryIntent(BaseModel):
    intent: Literal["product_search", "compatibility", "routine", "faq", "other"]
    confidence: float


class ConsultantResponse(BaseModel):
    response: str
    product_ids_referenced: list[str] = []


class RouteResult:
    def __init__(
        self,
        response: str,
        source: str,
        products_referenced: list[str] | None = None,
        model: str = "",
        tokens_in: int = 0,
        tokens_out: int = 0,
        cost_usd: float = 0.0,
    ) -> None:
        self.response = response
        self.source = source
        self.products_referenced = products_referenced or []
        self.model = model
        self.tokens_in = tokens_in
        self.tokens_out = tokens_out
        self.cost_usd = cost_usd


class LLMGateway:
    def __init__(self, settings: Settings, tenant_id: UUID) -> None:
        self._settings = settings
        self._tenant_id = tenant_id
        self._tier_to_model = {
            ModelTier.CLASSIFY: settings.llm_model_classify,
            ModelTier.GENERATE: settings.llm_model_generate,
            ModelTier.REASON:   settings.llm_model_reason,
        }
        self._last_usage: dict = {
            "model": "",
            "tokens_in": 0,
            "tokens_out": 0,
            "cost_usd": 0.0,
        }

    async def complete(
        self,
        tier: ModelTier,
        system: str,
        user: str,
        response_schema: Type[T],
        *,
        max_tokens: int = 1024,
        message_history: list[dict] | None = None,
    ) -> T:
        if message_history is None:
            message_history = []
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
            messages=[*message_history, {"role": "user", "content": user_with_schema}],
        )

        raw = message.content[0].text
        result = self._parse(raw, response_schema)
        if result is None:
            repair_msg = await client.messages.create(
                model=model_id,
                max_tokens=max_tokens,
                system=[{"type": "text", "text": system, "cache_control": {"type": "ephemeral"}}],
                messages=[
                    *message_history,
                    {"role": "user", "content": user_with_schema},
                    {"role": "assistant", "content": raw},
                    {"role": "user", "content": "Your response was not valid JSON. Return only the JSON object, nothing else."},
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
        self._last_usage["model"] = model_id
        self._last_usage["tokens_in"] += in_tokens
        self._last_usage["tokens_out"] += out_tokens
        self._last_usage["cost_usd"] = round(self._last_usage["cost_usd"] + cost_usd, 6)
        logger.info(
            "llm_call",
            model=model_id,
            call_type=call_type,
            tokens_in=in_tokens,
            tokens_out=out_tokens,
            cost_usd=round(cost_usd, 6),
            tenant_id=str(self._tenant_id),
        )

    async def classify_intent(
        self,
        query: str,
        cache: "LLMCache | None" = None,
    ) -> QueryIntent:
        _CLASSIFY_SYS = "Classify user query intent. Return only JSON."

        if cache:
            cached = await cache.get(self._tier_to_model[ModelTier.CLASSIFY], _CLASSIFY_SYS, query)
            if cached:
                return QueryIntent.model_validate(json.loads(cached))

        result = await self.complete(
            tier=ModelTier.CLASSIFY,
            system=_CLASSIFY_SYS,
            user=query,
            response_schema=QueryIntent,
            max_tokens=128,
        )

        if cache:
            await cache.set(
                self._tier_to_model[ModelTier.CLASSIFY],
                _CLASSIFY_SYS,
                query,
                result.model_dump_json(),
                ttl=86400,
            )
        return result

    async def route_query(
        self,
        query: str,
        system_prompt: str,
        context_products: list[dict],
        customer_profile: dict,
        pack_rules: list[dict],
        pack_templates: dict[str, str],
        cache: "LLMCache | None" = None,
        conversation_history: list[dict] | None = None,
    ) -> RouteResult:
        if conversation_history is None:
            conversation_history = []
        from helix.llm.layers import TemplateLayer, RuleEngineLayer

        self._last_usage = {
            "model": "",
            "tokens_in": 0,
            "tokens_out": 0,
            "cost_usd": 0.0,
        }

        await self.classify_intent(query, cache)
        self._last_usage = {
            "model": "",
            "tokens_in": 0,
            "tokens_out": 0,
            "cost_usd": 0.0,
        }  # track only generate tokens

        template_layer = TemplateLayer()
        template_result = await template_layer.query(query, pack_templates)
        if template_result.answered:
            return RouteResult(response=template_result.response, source="template")

        rule_layer = RuleEngineLayer()
        rule_result = await rule_layer.query(query, pack_rules)
        if rule_result.answered:
            return RouteResult(response=rule_result.response, source="rules")

        if context_products:
            product_list = "\n".join(
                f"- {p['title']} ({p.get('currency','?')} {p.get('price_minor',0)/100:.0f}): "
                f"{p.get('domain_attributes', {})}"
                for p in context_products[:5]
            )
            grounded_user = (
                f"Customer profile: {customer_profile}\n\n"
                f"Available products:\n{product_list}\n\n"
                f"Customer question: {query}"
            )
        else:
            grounded_user = f"Customer profile: {customer_profile}\n\nCustomer question: {query}"

        llm_result = await self.complete(
            tier=ModelTier.GENERATE,
            system=system_prompt,
            user=grounded_user,
            response_schema=ConsultantResponse,
            max_tokens=1024,
            message_history=conversation_history,
        )
        return RouteResult(
            response=llm_result.response,
            source="llm",
            products_referenced=llm_result.product_ids_referenced,
            model=self._last_usage["model"],
            tokens_in=self._last_usage["tokens_in"],
            tokens_out=self._last_usage["tokens_out"],
            cost_usd=self._last_usage["cost_usd"],
        )


# Defer import to avoid circular reference at module level
from helix.llm.cache import LLMCache  # noqa: E402
