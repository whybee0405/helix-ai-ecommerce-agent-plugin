"""Product description rewriter — uses Claude Haiku for SEO-optimised descriptions."""

from __future__ import annotations

import anthropic
import structlog
from fastapi import APIRouter, Depends
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.config import get_settings
from helix.db.models import Tenant

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


class DescriptionResponse(BaseModel):
    description_html: str


@router.post("/rewrite-description", response_model=DescriptionResponse)
async def rewrite_description(
    body: DescriptionRequest,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> DescriptionResponse:
    """Rewrite a product description in SEO-optimised HTML."""
    settings = get_settings()
    client = anthropic.AsyncAnthropic(api_key=settings.anthropic_api_key.get_secret_value())

    style_guidance = _PACK_STYLE.get(body.pack_id, _PACK_STYLE["general"])

    attrs_text = ""
    if body.domain_attributes:
        attrs_text = "\nProduct attributes:\n" + "\n".join(
            f"- {k}: {v}" for k, v in body.domain_attributes.items() if v
        )

    current_desc = body.current_description[:500] if body.current_description else "None provided."

    prompt = (
        f"Rewrite this product description as SEO-optimised HTML for '{body.product_title}'.\n\n"
        f"Current description: {current_desc}{attrs_text}\n\n"
        f"Style guidance: {style_guidance}\n\n"
        f"Requirements:\n"
        f"- Minimum 80 words\n"
        f"- Use <p> tags for paragraphs, <strong> for key features\n"
        f"- Optional: a short <ul> list of key benefits\n"
        f"- No <html>, <body>, or <head> tags\n"
        f"- Return ONLY the HTML, no explanation or preamble"
    )

    response = await client.messages.create(
        model="claude-haiku-4-5-20251001",
        max_tokens=600,
        messages=[{"role": "user", "content": prompt}],
    )

    description_html = response.content[0].text.strip()  # type: ignore[index]

    logger.info(
        "product_description_rewritten",
        tenant_id=str(tenant.id),
        product_title=body.product_title,
        pack_id=body.pack_id,
    )

    return DescriptionResponse(description_html=description_html)
