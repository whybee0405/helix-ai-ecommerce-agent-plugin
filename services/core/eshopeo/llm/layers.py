from dataclasses import dataclass
from typing import Any


@dataclass
class LayerResult:
    answered: bool
    response: Any | None = None
    confidence: float = 0.0


class VectorSearchLayer:
    """Layer 1: pgvector similarity search. Returns products; no LLM call."""

    async def query(self, tenant_id: str, query_text: str, top_k: int = 5) -> LayerResult:
        return LayerResult(answered=False)


class RuleEngineLayer:
    """Layer 2: compatibility + routine rules from the domain pack."""

    async def query(self, query_text: str, pack_rules: list[dict]) -> LayerResult:
        # Rule-based query matching is handled by route_query() in the gateway.
        # This stub returns unanswered; compatibility checking is via check_products().
        return LayerResult(answered=False)

    def check_products(
        self,
        products_attrs: list[dict],
        pack_rules: list[dict],
    ) -> "CompatibilityResult":
        from eshopeo.domain.rules import check_compatibility, CompatibilityResult
        return check_compatibility(products_attrs, pack_rules)


class TemplateLayer:
    """Layer 3: static FAQ / known-pattern templates from pack copy."""

    async def query(self, query_text: str, templates: dict[str, str]) -> LayerResult:
        q = query_text.lower()
        for key, answer in templates.items():
            if key.lower() in q:
                return LayerResult(answered=True, response=answer, confidence=1.0)
        return LayerResult(answered=False)
