"""Blog post generation router — uses Claude Sonnet for long-form content."""

from __future__ import annotations

import json as _json
import re

import anthropic
import structlog
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db, get_tenant, check_ai_op_quota, get_tenant_anthropic_key
from eshopeo.config import get_settings
from eshopeo.db.models import Tenant

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


class BlogResponse(BaseModel):
    title: str
    content_html: str
    excerpt: str
    suggested_categories: list[str]
    suggested_tags: list[str]
    seo_title: str
    seo_description: str


@router.post("/generate", response_model=BlogResponse)
async def generate_blog_post(
    body: BlogRequest,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
    db: AsyncSession = Depends(get_db),
) -> BlogResponse:
    """Generate a full blog post with SEO metadata."""
    settings = get_settings()
    client = anthropic.AsyncAnthropic(api_key=get_tenant_anthropic_key(tenant) or settings.anthropic_api_key.get_secret_value())

    site_context = _PACK_CONTEXT.get(body.pack_id, "an e-commerce retail store")
    if body.site_name:
        site_context = f"{site_context} called '{body.site_name}'"

    keywords_str = ", ".join(body.keywords) if body.keywords else "none specified"
    word_count   = max(200, min(2000, body.word_count))

    prompt = (
        f"Write a {word_count}-word blog post for {site_context}.\n\n"
        f"Topic: {body.topic}\n"
        f"Target keywords: {keywords_str}\n"
        f"Tone: {body.tone}\n\n"
        f"Return ONLY a JSON object with these fields:\n"
        f"- title: engaging blog post title (string)\n"
        f"- content_html: full HTML content with h2/h3 headings and paragraphs (string)\n"
        f"- excerpt: 1-2 sentence summary (string)\n"
        f"- suggested_categories: up to 3 relevant WP categories (array of strings)\n"
        f"- suggested_tags: up to 6 relevant tags (array of strings)\n"
        f"- seo_title: SEO-optimised title max 60 chars (string)\n"
        f"- seo_description: meta description max 160 chars (string)\n\n"
        f"Return raw JSON only — no markdown code fences."
    )

    response = await client.messages.create(
        model="claude-sonnet-4-6",
        max_tokens=4096,
        messages=[{"role": "user", "content": prompt}],
    )

    raw = response.content[0].text.strip()  # type: ignore[index]

    # Strip markdown code fences if present.
    raw = re.sub(r"^```(?:json)?\s*", "", raw)
    raw = re.sub(r"\s*```$", "", raw)

    try:
        data = _json.loads(raw)
    except _json.JSONDecodeError:
        match = re.search(r"\{.*\}", raw, re.DOTALL)
        if not match:
            raise HTTPException(
                status_code=status.HTTP_502_BAD_GATEWAY,
                detail=f"Model returned non-JSON: {raw[:300]}",
            )
        data = _json.loads(match.group())

    logger.info(
        "blog_post_generated",
        tenant_id=str(tenant.id),
        topic=body.topic,
        pack_id=body.pack_id,
    )

    return BlogResponse(
        title=data.get("title", body.topic),
        content_html=data.get("content_html", ""),
        excerpt=data.get("excerpt", ""),
        suggested_categories=data.get("suggested_categories", []),
        suggested_tags=data.get("suggested_tags", []),
        seo_title=data.get("seo_title", "")[:60],
        seo_description=data.get("seo_description", "")[:160],
    )
