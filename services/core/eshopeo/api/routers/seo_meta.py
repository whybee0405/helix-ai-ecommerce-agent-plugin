"""
SEO meta generation router — uses Claude Haiku to write title + description.

POST /v1/seo-meta/generate — generate SEO title + meta description for a post
"""

from __future__ import annotations

import structlog
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, Field
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db, get_tenant, check_ai_op_quota, get_tenant_anthropic_key
from eshopeo.config import get_settings
from eshopeo.db.models import Tenant
from eshopeo.llm.gateway import GenerationMeta, LLMGateway, LLMParseError, ModelTier

logger = structlog.get_logger(__name__)

router = APIRouter(prefix="/v1/seo-meta", tags=["seo-meta"])


class SeoMetaRequest(BaseModel):
    post_title: str
    post_excerpt: str = ""
    pack_id: str = "general"


class _SeoMetaLLM(BaseModel):
    title: str = Field(description="SEO title, max 60 chars")
    description: str = Field(description="meta description, max 160 chars")


class SeoMetaResponse(BaseModel):
    seo_title: str
    seo_description: str
    meta: GenerationMeta


@router.post("/generate", response_model=SeoMetaResponse)
async def generate_seo_meta(
    body: SeoMetaRequest,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
    db: AsyncSession = Depends(get_db),
) -> SeoMetaResponse:
    """POST /v1/seo-meta/generate — Generate SEO title (≤60 chars) and meta description (≤160 chars)."""
    settings = get_settings()
    excerpt_snippet = body.post_excerpt[:200] if body.post_excerpt else "none"

    system_prompt = "You write concise SEO titles and meta descriptions. Return valid JSON only."
    user_prompt = (
        f"Generate an SEO title and meta description for a page titled '{body.post_title}'. "
        f"Excerpt: {excerpt_snippet}"
    )

    gateway = LLMGateway(settings, tenant.id, api_key_override=get_tenant_anthropic_key(tenant))

    try:
        result = await gateway.complete(
            ModelTier.CLASSIFY,
            system_prompt,
            user_prompt,
            _SeoMetaLLM,
            max_tokens=120,
        )
    except LLMParseError as exc:
        logger.error("seo_meta_parse_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="The AI returned an unreadable response. Please try again.",
        )
    except Exception as exc:  # anthropic SDK errors (timeout/connection/status)
        logger.error("seo_meta_llm_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="Could not reach the AI provider. Please try again in a moment.",
        )

    seo_title = result.title.strip()[:60]
    seo_description = result.description.strip()[:160]
    if not seo_title or not seo_description:
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="The AI returned an incomplete response. Please try again.",
        )

    logger.info(
        "seo_meta_generated",
        tenant_id=str(tenant.id),
        post_title=body.post_title,
    )

    usage = gateway.last_usage
    return SeoMetaResponse(
        seo_title=seo_title,
        seo_description=seo_description,
        meta=GenerationMeta(model=usage["model"], cost_usd=usage["cost_usd"]),
    )
