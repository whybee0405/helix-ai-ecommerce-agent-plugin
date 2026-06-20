from uuid import UUID

import structlog

from helix.branding import Branding
from helix.config import Settings
from helix.llm.cache import LLMCache
from helix.llm.gateway import LLMGateway, RouteResult
from helix.llm.semantic_cache import SemanticCache
from helix.packs.loader import LoadedPack

logger = structlog.get_logger(__name__)


async def _maybe_inject_web_search_context(
    query: str,
    settings: Settings,
    system_prompt: str,
) -> str:
    """Call Brave Search and append top results to the system prompt."""
    if settings.brave_api_key is None:
        return system_prompt
    try:
        from helix.tools.web_search import brave_search

        results = await brave_search(
            query, settings.brave_api_key.get_secret_value()
        )
        if results:
            snippets = "\n".join(
                f"- {r['title']}: {r['snippet']} ({r['url']})" for r in results
            )
            return system_prompt + f"\n\nWeb search results for context:\n{snippets}"
    except Exception as e:  # noqa: BLE001
        logger.warning("web_search_context_failed", error=str(e))
    return system_prompt


async def _maybe_inject_faq_context(
    query: str,
    tenant_id: UUID,
    settings: Settings,
    db_session,
    system_prompt: str,
) -> str:
    """If FAQs with embeddings exist for the tenant, vector-search them and inject
    the best match into the system prompt. Returns the (possibly extended) prompt."""
    try:
        from helix.domain.search import embed_query
        from helix.db.crud.faqs import vector_search_faqs

        qvec = await embed_query(query, settings)
        hits = await vector_search_faqs(db_session, tenant_id, qvec, limit=1)
        if hits:
            faq, distance = hits[0]
            if distance < 0.25:  # close enough to be useful
                faq_snippet = (
                    f"\n\nRelevant FAQ:\nQ: {faq.question}\nA: {faq.answer}"
                )
                return system_prompt + faq_snippet
    except Exception as e:  # noqa: BLE001
        logger.warning("faq_context_inject_failed", error=str(e))
    return system_prompt


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

    # Classify intent early so we can enrich context for FAQ queries
    intent = None
    try:
        cache = LLMCache(settings)
        intent = await gateway.classify_intent(query, cache, cache_namespace=cache_namespace)
        await cache.aclose()
        cache = LLMCache(settings)  # fresh handle for route_query
    except Exception as e:  # noqa: BLE001
        logger.warning("consultant_intent_prefetch_failed", error=str(e))
        cache = LLMCache(settings)

    if intent is not None and intent.intent == "faq":
        system_prompt = await _maybe_inject_faq_context(
            query, tenant_id, settings, db_session, system_prompt
        )

    # Web search: use when intent is web_search or no relevant products found
    if intent is not None and (
        intent.intent == "web_search"
        or (intent.intent == "other" and not context_products and settings.brave_api_key)
    ):
        system_prompt = await _maybe_inject_web_search_context(query, settings, system_prompt)

    # Detect escalation intent and return a structured escalate block
    if intent is not None and intent.intent == "escalate":
        await cache.aclose()
        await sem_cache.aclose()
        return RouteResult(
            response=(
                '```json\n{"type": "escalate"}\n```\n\n'
                "No problem — let me connect you with a member of our team. "
                "Please share your email and any details and we'll be in touch shortly."
            ),
            source="escalate_intent",
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
            cache_namespace=cache_namespace,
            conversation_history=conversation_history,
            semantic_cache=sem_cache,
            budget_mode=budget_mode,
        )
    finally:
        await cache.aclose()
        await sem_cache.aclose()

    return result
