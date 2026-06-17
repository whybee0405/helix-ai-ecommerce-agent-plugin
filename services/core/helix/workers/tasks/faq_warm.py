"""FAQ pre-warming.

For each tenant, run the preset's seed queries through the full chat pipeline
once and store results in the semantic cache with a long TTL. First-time
visitors then hit warm cache for the most common questions and we pay zero
LLM cost on those turns.

Scheduled weekly + triggered on tenant provision and major catalog changes.
"""

from __future__ import annotations

import asyncio
import os
from uuid import UUID

import structlog

from helix.config import get_settings
from helix.workers.celery_app import celery_app

logger = structlog.get_logger(__name__)


@celery_app.task(name="helix.workers.tasks.faq_warm.warm_tenant_faq", queue="embedding")
def warm_tenant_faq(tenant_id_str: str) -> dict:
    return asyncio.run(_warm_async(UUID(tenant_id_str)))


@celery_app.task(name="helix.workers.tasks.faq_warm.warm_all_tenants", queue="embedding")
def warm_all_tenants() -> dict:
    return asyncio.run(_warm_all_async())


async def _warm_async(tenant_id: UUID) -> dict:
    from helix.branding import get_effective_branding
    from helix.db.crud.tenants import get_tenant_by_id
    from helix.db.engine import async_session_factory
    from helix.domain.consultant import handle_query
    from helix.domain.search import embed_query
    from helix.db.crud.products import vector_search_products
    from helix.packs.registry import get_pack_for_tenant

    settings = get_settings()
    warmed = 0
    failed = 0
    async with async_session_factory() as db:
        tenant = await get_tenant_by_id(db, tenant_id)
        if tenant is None:
            return {"warmed": 0, "failed": 0, "reason": "tenant_not_found"}
        branding = await get_effective_branding(tenant)
        pack = get_pack_for_tenant(tenant)
        for q in branding.faq_seed_queries:
            try:
                qvec = await embed_query(q, settings)
                product_rows = await vector_search_products(db, tenant.id, qvec, limit=5)
                context = [
                    {
                        "platform_id": p.platform_id,
                        "title": p.title,
                        "price_minor": p.price_minor,
                        "currency": p.currency,
                        "images": p.images or [],
                        "categories": p.categories or [],
                        "domain_attributes": p.domain_attributes or {},
                    }
                    for p, _ in product_rows
                ]
                await handle_query(
                    query=q,
                    customer_profile={},
                    context_products=context,
                    tenant_id=tenant.id,
                    pack=pack,
                    settings=settings,
                    db_session=db,
                    conversation_history=None,
                    branding=branding,
                    branding_version=tenant.branding_version,
                )
                warmed += 1
            except Exception as e:  # noqa: BLE001
                logger.warning("faq_warm_query_failed", tenant_id=str(tenant_id), query=q, error=str(e))
                failed += 1
    logger.info("faq_warm_done", tenant_id=str(tenant_id), warmed=warmed, failed=failed)
    return {"warmed": warmed, "failed": failed}


async def _warm_all_async() -> dict:
    from helix.db.crud.tenants import list_tenants
    from helix.db.engine import async_session_factory

    out = {"total": 0, "warmed": 0, "failed": 0}
    async with async_session_factory() as db:
        tenants = await list_tenants(db, limit=1000, offset=0)
    out["total"] = len(tenants)
    for t in tenants:
        try:
            r = await _warm_async(t.id)
            out["warmed"] += r.get("warmed", 0)
            out["failed"] += r.get("failed", 0)
        except Exception as e:  # noqa: BLE001
            logger.warning("faq_warm_tenant_failed", tenant_id=str(t.id), error=str(e))
            out["failed"] += 1
    return out
