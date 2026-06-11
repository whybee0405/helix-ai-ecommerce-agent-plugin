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
        return LayerResult(answered=False)


class TemplateLayer:
    """Layer 3: static FAQ / known-pattern templates from pack copy."""

    async def query(self, query_text: str, templates: dict[str, str]) -> LayerResult:
        return LayerResult(answered=False)
