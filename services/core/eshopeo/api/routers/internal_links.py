"""
Internal links suggestion router — uses Claude Haiku to find linking opportunities.

POST /v1/internal-links/suggest — suggest up to 5 internal link opportunities for a post
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


class _InternalLinksLLM(BaseModel):
    suggestions: list[LinkSuggestion] = []


class InternalLinksResponse(BaseModel):
    suggestions: list[LinkSuggestion]
    meta: GenerationMeta | None = None


@router.post("/suggest", response_model=InternalLinksResponse)
async def suggest_links(
    body: InternalLinksRequest,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
    db: AsyncSession = Depends(get_db),
) -> InternalLinksResponse:
    """POST /v1/internal-links/suggest — Suggest up to 5 internal link opportunities for a given post."""
    if not body.all_posts:
        return InternalLinksResponse(suggestions=[])

    settings = get_settings()

    # Build compact post list.
    post_list = "\n".join(
        f"- ID:{p.id} | {p.title} | {p.url}"
        + (f" | {p.excerpt[:100]}" if p.excerpt else "")
        for p in body.all_posts[:60]
    )

    content_snippet = body.post_content[:1500]

    system_prompt = (
        "You are an SEO expert who suggests internal linking opportunities. "
        "Return valid JSON only."
    )
    user_prompt = (
        f"Given the post titled '{body.post_title}' with this content:\n\n"
        f"{content_snippet}\n\n"
        f"And these other published posts on the same site:\n{post_list}\n\n"
        f"Suggest up to 5 internal link opportunities. For each, identify a phrase "
        f"that already appears verbatim in the post content (anchor_text), and match it "
        f"to the most relevant target post (target_url, target_title)."
    )

    gateway = LLMGateway(settings, tenant.id, api_key_override=get_tenant_anthropic_key(tenant))

    try:
        result = await gateway.complete(
            ModelTier.CLASSIFY,
            system_prompt,
            user_prompt,
            _InternalLinksLLM,
            max_tokens=600,
        )
    except LLMParseError as exc:
        logger.error("internal_links_parse_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="The AI returned an unreadable response. Please try again.",
        )
    except Exception as exc:  # anthropic SDK errors (timeout/connection/status)
        logger.error("internal_links_llm_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="Could not reach the AI provider. Please try again in a moment.",
        )

    suggestions = [s for s in result.suggestions if s.anchor_text and s.target_url]

    logger.info(
        "internal_links_suggested",
        tenant_id=str(tenant.id),
        post_title=body.post_title,
        suggestion_count=len(suggestions),
    )

    usage = gateway.last_usage
    return InternalLinksResponse(
        suggestions=suggestions,
        meta=GenerationMeta(model=usage["model"], cost_usd=usage["cost_usd"]),
    )
