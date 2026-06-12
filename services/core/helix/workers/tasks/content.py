import asyncio
from uuid import UUID

import structlog
from pydantic import BaseModel

from helix.workers.celery_app import celery_app
from helix.config import get_settings
from helix.db.engine import async_session_factory
from helix.db.crud.products import get_product_by_id
from helix.db.crud.tenants import get_tenant_by_id
from helix.db.crud.content import upsert_content_draft
from helix.packs.registry import get_pack_for_tenant
from helix.llm.gateway import LLMGateway, ModelTier

logger = structlog.get_logger(__name__)


class DescriptionDraft(BaseModel):
    html: str


def _build_system_prompt(pack) -> str:
    return (
        "You are a product copywriter for an e-commerce store. "
        "Write compelling, SEO-optimised product descriptions grounded in the product data provided. "
        "Do not invent claims not supported by the product attributes. "
        "Return valid JSON only."
    )


def _build_user_prompt(product) -> str:
    attrs = product.domain_attributes or {}
    attr_lines = "\n".join(f"- {k}: {v}" for k, v in attrs.items() if v)
    price = product.price_minor / 100
    cats = ", ".join(product.categories or [])
    return (
        f"Write an HTML product description for:\n\n"
        f"Title: {product.title}\n"
        f"Price: {price:.2f} {product.currency}\n"
        f"Categories: {cats}\n"
        f"Attributes:\n{attr_lines}\n\n"
        f"2-4 short paragraphs. Return JSON with a single 'html' key containing the HTML body "
        f"(no <html>/<body> wrappers)."
    )


async def _generate_async(tenant_id_str: str, product_id_str: str) -> None:
    tenant_id = UUID(tenant_id_str)
    product_id = UUID(product_id_str)
    settings = get_settings()

    async with async_session_factory() as session:
        tenant = await get_tenant_by_id(session, tenant_id)
        product = await get_product_by_id(session, tenant_id, product_id)
        if not tenant or not product:
            logger.warning(
                "generate_description_not_found",
                tenant_id=tenant_id_str,
                product_id=product_id_str,
            )
            return

        pack = get_pack_for_tenant(tenant)
        gateway = LLMGateway(settings, tenant_id)
        result = await gateway.complete(
            ModelTier.GENERATE,
            _build_system_prompt(pack),
            _build_user_prompt(product),
            DescriptionDraft,
            max_tokens=2048,
        )

        await upsert_content_draft(
            session, tenant_id, product_id, "description_html", result.html
        )
        await session.commit()
        logger.info("generate_description_done", product_id=product_id_str)


@celery_app.task(
    bind=True,
    max_retries=3,
    default_retry_delay=60,
    name="helix.workers.tasks.content.generate_description",
)
def generate_description(self, tenant_id_str: str, product_id_str: str) -> None:
    try:
        asyncio.run(_generate_async(tenant_id_str, product_id_str))
    except Exception as exc:
        raise self.retry(exc=exc)
