"""
Product description rewriter — uses Claude Haiku for SEO-optimised descriptions.

POST /v1/products/rewrite-description — rewrite a product description as SEO HTML
"""

from __future__ import annotations

import structlog
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db, get_tenant, check_ai_op_quota, get_tenant_anthropic_key
from eshopeo.config import get_settings
from eshopeo.db.models import Tenant
from eshopeo.llm.gateway import GenerationMeta, LLMGateway, LLMParseError, ModelTier

logger = structlog.get_logger(__name__)

router = APIRouter(prefix="/v1/products", tags=["products"])

_PACK_STYLE: dict[str, str] = {
    "kbeauty": (
        "Focus on skin benefits, ingredients, and skincare routine integration. "
        "Use sensory language. Mention skin type suitability."
    ),
    "automotive": (
        "Highlight performance specs, safety features, and lifestyle appeal. "
        "Be confident and aspirational."
    ),
    "general": (
        "Be benefit-led, scannable, and conversion-focused. "
        "Use short sentences and clear value propositions."
    ),
}


class DescriptionRequest(BaseModel):
    product_title: str
    current_description: str = ""
    domain_attributes: dict = {}
    pack_id: str = "general"


class _DescriptionLLM(BaseModel):
    html: str


class DescriptionResponse(BaseModel):
    description_html: str
    meta: GenerationMeta


@router.post("/rewrite-description", response_model=DescriptionResponse)
async def rewrite_description(
    body: DescriptionRequest,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
    db: AsyncSession = Depends(get_db),
) -> DescriptionResponse:
    """POST /v1/products/rewrite-description — Rewrite a product description in SEO-optimised HTML."""
    settings = get_settings()
    style_guidance = _PACK_STYLE.get(body.pack_id, _PACK_STYLE["general"])

    attrs_text = ""
    if body.domain_attributes:
        attrs_text = "\nProduct attributes:\n" + "\n".join(
            f"- {k}: {v}" for k, v in body.domain_attributes.items() if v
        )

    current_desc = body.current_description[:500] if body.current_description else "None provided."

    system_prompt = (
        "You are a product copywriter for an e-commerce store. Return valid JSON only."
    )
    user_prompt = (
        f"Rewrite this product description as SEO-optimised HTML for '{body.product_title}'.\n\n"
        f"Current description: {current_desc}{attrs_text}\n\n"
        f"Style guidance: {style_guidance}\n\n"
        f"Requirements:\n"
        f"- Minimum 80 words\n"
        f"- Use <p> tags for paragraphs, <strong> for key features\n"
        f"- Optional: a short <ul> list of key benefits\n"
        f"- No <html>, <body>, or <head> tags\n"
        f"- Put the HTML in a single 'html' field, no explanation or preamble"
    )

    gateway = LLMGateway(settings, tenant.id, api_key_override=get_tenant_anthropic_key(tenant))

    try:
        result = await gateway.complete(
            ModelTier.CLASSIFY,
            system_prompt,
            user_prompt,
            _DescriptionLLM,
            max_tokens=600,
        )
    except LLMParseError as exc:
        logger.error("product_description_parse_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="The AI returned an unreadable response. Please try again.",
        )
    except Exception as exc:  # anthropic SDK errors (timeout/connection/status)
        logger.error("product_description_llm_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="Could not reach the AI provider. Please try again in a moment.",
        )

    description_html = result.html.strip()
    if len(description_html) < 40 or "<" not in description_html:
        # Catches refusals/degenerate output ("I can't help with that") rather
        # than silently writing junk into the product description.
        logger.error(
            "product_description_suspect_output",
            tenant_id=str(tenant.id),
            preview=description_html[:120],
        )
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="The AI did not return a usable description. Please try again.",
        )

    logger.info(
        "product_description_rewritten",
        tenant_id=str(tenant.id),
        product_title=body.product_title,
        pack_id=body.pack_id,
    )

    usage = gateway.last_usage
    return DescriptionResponse(
        description_html=description_html,
        meta=GenerationMeta(model=usage["model"], cost_usd=usage["cost_usd"]),
    )
