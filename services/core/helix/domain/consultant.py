from uuid import UUID

import structlog

from helix.config import Settings
from helix.llm.cache import LLMCache
from helix.llm.gateway import LLMGateway, RouteResult
from helix.packs.loader import LoadedPack

logger = structlog.get_logger(__name__)


async def handle_query(
    query: str,
    customer_profile: dict,
    context_products: list[dict],
    tenant_id: UUID,
    pack: LoadedPack,
    settings: Settings,
    db_session,
    conversation_history: list[dict] = [],
) -> RouteResult:
    gateway = LLMGateway(settings=settings, tenant_id=tenant_id)
    cache = LLMCache(settings)

    system_prompt = pack.prompts.get("system", "You are a helpful advisor.").replace(
        "{brand_name}", settings.brand_name
    )

    try:
        result = await gateway.route_query(
            query=query,
            system_prompt=system_prompt,
            context_products=context_products,
            customer_profile=customer_profile,
            pack_rules=pack.compatibility_rules,
            pack_templates=pack.copy.get("en", {}).get("widget", {}),
            cache=cache,
            conversation_history=conversation_history,
        )
    finally:
        await cache.aclose()

    return result
