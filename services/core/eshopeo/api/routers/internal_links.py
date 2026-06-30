"""Internal links suggestion router — uses Claude Haiku to find linking opportunities."""

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

router = APIRouter(prefix="/v1/internal-links", tags=["internal-links"])


class PostSummary(BaseModel):
    id: int
    title: str
    url: str
    excerpt: str = ""


class InternalLinksRequest(BaseModel):
    post_title: str
    post_content: str
    all_posts: list[PostSummary]


class LinkSuggestion(BaseModel):
    anchor_text: str
    target_url: str
    target_title: str


class InternalLinksResponse(BaseModel):
    suggestions: list[LinkSuggestion]


@router.post("/suggest", response_model=InternalLinksResponse)
async def suggest_links(
    body: InternalLinksRequest,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
    db: AsyncSession = Depends(get_db),
) -> InternalLinksResponse:
    """Suggest up to 5 internal link opportunities for a given post."""
    if not body.all_posts:
        return InternalLinksResponse(suggestions=[])

    settings = get_settings()
    client = anthropic.AsyncAnthropic(api_key=get_tenant_anthropic_key(tenant) or settings.anthropic_api_key.get_secret_value())

    # Build compact post list.
    post_list = "\n".join(
        f"- ID:{p.id} | {p.title} | {p.url}"
        + (f" | {p.excerpt[:100]}" if p.excerpt else "")
        for p in body.all_posts[:60]
    )

    content_snippet = body.post_content[:1500]

    prompt = (
        f"You are an SEO expert. Given the post titled '{body.post_title}' with this content:\n\n"
        f"{content_snippet}\n\n"
        f"And these other published posts on the same site:\n{post_list}\n\n"
        f"Suggest up to 5 internal link opportunities. For each, identify a phrase "
        f"that already appears verbatim in the post content, and match it to the most "
        f"relevant target post URL.\n\n"
        f"Return ONLY a JSON array:\n"
        f'[{{"anchor_text":"<exact phrase from content>","target_url":"<url>","target_title":"<title>"}}]'
    )

    response = await client.messages.create(
        model="claude-haiku-4-5-20251001",
        max_tokens=600,
        messages=[{"role": "user", "content": prompt}],
    )

    raw = response.content[0].text.strip()  # type: ignore[index]

    try:
        suggestions_raw = _json.loads(raw)
    except _json.JSONDecodeError:
        match = re.search(r"\[.*\]", raw, re.DOTALL)
        if not match:
            raise HTTPException(
                status_code=status.HTTP_502_BAD_GATEWAY,
                detail=f"Model returned non-JSON: {raw[:200]}",
            )
        suggestions_raw = _json.loads(match.group())

    suggestions = [
        LinkSuggestion(
            anchor_text=s.get("anchor_text", ""),
            target_url=s.get("target_url", ""),
            target_title=s.get("target_title", ""),
        )
        for s in suggestions_raw
        if s.get("anchor_text") and s.get("target_url")
    ]

    logger.info(
        "internal_links_suggested",
        tenant_id=str(tenant.id),
        post_title=body.post_title,
        suggestion_count=len(suggestions),
    )

    return InternalLinksResponse(suggestions=suggestions)
