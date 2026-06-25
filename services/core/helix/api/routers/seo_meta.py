"""SEO meta generation router — uses Claude Haiku to write title + description."""

from __future__ import annotations

import json as _json

import anthropic
import structlog
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant, check_ai_op_quota, get_tenant_anthropic_key
from helix.config import get_settings
from helix.db.models import Tenant

logger = structlog.get_logger(__name__)

router = APIRouter(prefix="/v1/seo-meta", tags=["seo-meta"])


class SeoMetaRequest(BaseModel):
    post_title: str
    post_excerpt: str = ""
    pack_id: str = "general"


class SeoMetaResponse(BaseModel):
    seo_title: str
    seo_description: str


@router.post("/generate", response_model=SeoMetaResponse)
async def generate_seo_meta(
    body: SeoMetaRequest,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
    db: AsyncSession = Depends(get_db),
) -> SeoMetaResponse:
    """Generate SEO title (≤60 chars) and meta description (≤160 chars)."""
    settings = get_settings()
    client = anthropic.AsyncAnthropic(api_key=get_tenant_anthropic_key(tenant) or settings.anthropic_api_key.get_secret_value())

    excerpt_snippet = body.post_excerpt[:200] if body.post_excerpt else "none"
    prompt = (
        f"Generate an SEO title (max 60 chars) and meta description (max 160 chars) "
        f"for a page titled '{body.post_title}'. "
        f"Excerpt: {excerpt_snippet}. "
        f'Return JSON only: {{"title": "...", "description": "..."}}'
    )

    response = await client.messages.create(
        model="claude-haiku-4-5-20251001",
        max_tokens=120,
        messages=[{"role": "user", "content": prompt}],
    )

    raw = response.content[0].text.strip()  # type: ignore[index]

    try:
        data = _json.loads(raw)
    except _json.JSONDecodeError:
        # Try to extract JSON from the response.
        import re
        match = re.search(r"\{.*\}", raw, re.DOTALL)
        if not match:
            raise HTTPException(
                status_code=status.HTTP_502_BAD_GATEWAY,
                detail=f"Model returned non-JSON: {raw[:200]}",
            )
        data = _json.loads(match.group())

    logger.info(
        "seo_meta_generated",
        tenant_id=str(tenant.id),
        post_title=body.post_title,
    )

    return SeoMetaResponse(
        seo_title=data.get("title", "")[:60],
        seo_description=data.get("description", "")[:160],
    )
