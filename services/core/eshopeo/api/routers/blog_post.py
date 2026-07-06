"""
Blog post generation router — uses Claude Sonnet for long-form content.

POST /v1/blog/generate — generate a full blog post with SEO metadata
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

router = APIRouter(prefix="/v1/blog", tags=["blog"])

_PACK_CONTEXT: dict[str, str] = {
    "kbeauty": "a K-beauty and skincare e-commerce store",
    "automotive": "an automotive dealership and vehicle sales website",
    "general": "an e-commerce retail store",
}


class BlogRequest(BaseModel):
    topic: str
    keywords: list[str] = []
    tone: str = "professional"
    word_count: int = 800
    pack_id: str = "general"
    site_name: str = ""


class _BlogLLM(BaseModel):
    title: str = Field(description="engaging blog post title")
    content_html: str = Field(description="full HTML content with h2/h3 headings and paragraphs, no html/body wrappers")
    excerpt: str = Field(description="1-2 sentence summary")
    suggested_categories: list[str] = Field(default=[], description="up to 3 relevant WordPress categories")
    suggested_tags: list[str] = Field(default=[], description="up to 6 relevant tags")
    seo_title: str = Field(description="SEO-optimised title, max 60 chars")
    seo_description: str = Field(description="meta description, max 160 chars")


class BlogResponse(BaseModel):
    title: str
    content_html: str
    excerpt: str
    suggested_categories: list[str]
    suggested_tags: list[str]
    seo_title: str
    seo_description: str
    meta: GenerationMeta


@router.post("/generate", response_model=BlogResponse)
async def generate_blog_post(
    body: BlogRequest,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
    db: AsyncSession = Depends(get_db),
) -> BlogResponse:
    """POST /v1/blog/generate — Generate a full blog post with SEO metadata."""
    settings = get_settings()

    site_context = _PACK_CONTEXT.get(body.pack_id, "an e-commerce retail store")
    if body.site_name:
        site_context = f"{site_context} called '{body.site_name}'"

    keywords_str = ", ".join(body.keywords) if body.keywords else "none specified"
    word_count = max(200, min(2000, body.word_count))

    system_prompt = (
        "You are a content writer for an e-commerce store's blog. Return valid JSON only."
    )
    user_prompt = (
        f"Write a {word_count}-word blog post for {site_context}.\n\n"
        f"Topic: {body.topic}\n"
        f"Target keywords: {keywords_str}\n"
        f"Tone: {body.tone}"
    )

    gateway = LLMGateway(settings, tenant.id, api_key_override=get_tenant_anthropic_key(tenant))

    try:
        result = await gateway.complete(
            ModelTier.GENERATE,
            system_prompt,
            user_prompt,
            _BlogLLM,
            max_tokens=4096,
        )
    except LLMParseError as exc:
        logger.error("blog_post_parse_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="The AI returned an unreadable response. Please try again.",
        )
    except Exception as exc:  # anthropic SDK errors (timeout/connection/status)
        logger.error("blog_post_llm_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="Could not reach the AI provider. Please try again in a moment.",
        )

    content_html = result.content_html.strip()
    if len(content_html) < 200 or "<" not in content_html:
        logger.error(
            "blog_post_suspect_output",
            tenant_id=str(tenant.id),
            preview=content_html[:120],
        )
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="The AI did not return a usable blog post. Please try again.",
        )

    logger.info(
        "blog_post_generated",
        tenant_id=str(tenant.id),
        topic=body.topic,
        pack_id=body.pack_id,
    )

    usage = gateway.last_usage
    return BlogResponse(
        title=result.title or body.topic,
        content_html=content_html,
        excerpt=result.excerpt,
        suggested_categories=result.suggested_categories,
        suggested_tags=result.suggested_tags,
        seo_title=result.seo_title[:60],
        seo_description=result.seo_description[:160],
        meta=GenerationMeta(model=usage["model"], cost_usd=usage["cost_usd"]),
    )
