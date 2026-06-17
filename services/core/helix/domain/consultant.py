from uuid import UUID

import structlog

from helix.branding import Branding
from helix.config import Settings
from helix.llm.cache import LLMCache
from helix.llm.gateway import LLMGateway, RouteResult
from helix.llm.semantic_cache import SemanticCache
from helix.packs.loader import LoadedPack

logger = structlog.get_logger(__name__)


def _build_system_prompt(pack: LoadedPack, branding: Branding | None, fallback_brand: str) -> str:
    base = pack.prompts.get("system", "You are a helpful advisor.")
    brand_name = branding.brand_name if branding else fallback_brand
    base = base.replace("{brand_name}", brand_name).replace("{{brand_name}}", brand_name)
    if branding is None:
        return base
    extras = (
        f"You are {branding.brand_name}, {branding.tagline}. "
        f"Tone: {branding.tone}. "
        f"Locale: {branding.locale}. Currency: {branding.currency}."
    )
    return base + "\n\n" + extras


async def handle_query(
    query: str,
    customer_profile: dict,
    context_products: list[dict],
    tenant_id: UUID,
    pack: LoadedPack,
    settings: Settings,
    db_session,
    conversation_history: list[dict] | None = None,
    branding: Branding | None = None,
    branding_version: int = 0,
    budget_mode: str = "normal",
) -> RouteResult:
    if conversation_history is None:
        conversation_history = []
    gateway = LLMGateway(settings=settings, tenant_id=tenant_id)
    cache = LLMCache(settings)
    sem_cache = SemanticCache(settings)

    system_prompt = _build_system_prompt(pack, branding, settings.brand_name)
    cache_namespace = f"t={tenant_id}:bv={branding_version}"

    try:
        result = await gateway.route_query(
            query=query,
            system_prompt=system_prompt,
            context_products=context_products,
            customer_profile=customer_profile,
            pack_rules=pack.compatibility_rules,
            pack_templates=pack.copy.get("en", {}).get("widget", {}),
            cache=cache,
            cache_namespace=cache_namespace,
            conversation_history=conversation_history,
            semantic_cache=sem_cache,
            budget_mode=budget_mode,
        )
    finally:
        await cache.aclose()
        await sem_cache.aclose()

    return result
