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
from helix.llm.gateway import LLMGateway, LLMParseError, ModelTier

logger = structlog.get_logger(__name__)


class SeoMeta(BaseModel):
    meta_title: str
    meta_description: str


_SYSTEM_PROMPT = (
    "You are an SEO specialist. Write a meta title (max 60 characters) and meta description "
    "(max 155 characters) for the product below. Focus on the primary benefit and key attributes. "
    "No keyword stuffing. Return only valid JSON."
)


def _build_user_prompt(product) -> str:
    attrs = product.domain_attributes or {}
    attr_lines = "\n".join(f"- {k}: {v}" for k, v in attrs.items() if v is not None)
    price = product.price_minor / 100
    cats = ", ".join(product.categories or [])
    return (
        f"Write SEO meta title and meta description for:\n\n"
        f"Title: {product.title}\n"
        f"Price: {price:.2f} {product.currency}\n"
        f"Categories: {cats}\n"
        f"Attributes:\n{attr_lines}\n\n"
        f"Return JSON with 'meta_title' and 'meta_description' keys."
    )


async def _generate_seo_async(tenant_id_str: str, product_id_str: str) -> None:
    tenant_id = UUID(tenant_id_str)
    product_id = UUID(product_id_str)
    settings = get_settings()

    async with async_session_factory() as session:
        tenant = await get_tenant_by_id(session, tenant_id)
        product = await get_product_by_id(session, tenant_id, product_id)
        if not tenant or not product:
            logger.warning(
                "generate_seo_metadata_not_found",
                tenant_id=tenant_id_str,
                product_id=product_id_str,
            )
            return

        gateway = LLMGateway(settings, tenant_id)
        result = await gateway.complete(
            ModelTier.GENERATE,
            _SYSTEM_PROMPT,
            _build_user_prompt(product),
            SeoMeta,
            max_tokens=512,
        )

        await upsert_content_draft(
            session, tenant_id, product_id, "meta_title", result.meta_title
        )
        await upsert_content_draft(
            session, tenant_id, product_id, "meta_description", result.meta_description
        )
        await session.commit()
        logger.info("generate_seo_metadata_done", product_id=product_id_str)


@celery_app.task(
    bind=True,
    max_retries=3,
    default_retry_delay=60,
    name="helix.workers.tasks.seo.generate_seo_metadata",
)
def generate_seo_metadata(self, tenant_id_str: str, product_id_str: str) -> None:
    try:
        asyncio.run(_generate_seo_async(tenant_id_str, product_id_str))
    except LLMParseError as exc:
        # Parsing failures won't self-heal on retry; log and drop.
        logger.error("generate_seo_metadata_parse_failure", product_id=product_id_str, error=str(exc))
    except Exception as exc:
        raise self.retry(exc=exc)
