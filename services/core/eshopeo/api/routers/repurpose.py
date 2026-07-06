"""
Content repurposing router — uses Claude Sonnet to generate multiple formats.

POST /v1/content/repurpose — repurpose a post into social/email/faq/summary formats
"""

from __future__ import annotations

from typing import Optional

import structlog
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db, get_tenant, check_ai_op_quota, get_tenant_anthropic_key
from eshopeo.config import get_settings
from eshopeo.db.models import Tenant
from eshopeo.llm.gateway import GenerationMeta, LLMGateway, LLMParseError, ModelTier

logger = structlog.get_logger(__name__)

router = APIRouter(prefix="/v1/content", tags=["content"])

_ALLOWED_FORMATS = {"social_caption", "email", "faq", "short_summary"}

_FORMAT_HINTS: dict[str, str] = {
    "social_caption": "engaging social media caption, max 280 chars, include 2-3 relevant hashtags",
    "email": "email newsletter body, 150-200 words, with subject hint in first line",
    "faq": "3-5 FAQ pairs as a list of {question, answer} objects",
    "short_summary": "concise 2-3 sentence summary suitable for a featured snippet",
}


class RepurposeRequest(BaseModel):
    source_title: str
    source_content: str
    formats: list[str]  # social_caption, email, faq, short_summary
    pack_id: str = "general"


class _RepurposeLLM(BaseModel):
    social_caption: Optional[str] = None
    email_body: Optional[str] = None
    faq: Optional[list[dict]] = None
    short_summary: Optional[str] = None


class RepurposeResponse(BaseModel):
    social_caption: Optional[str] = None
    email_body: Optional[str] = None
    faq: Optional[list[dict]] = None
    short_summary: Optional[str] = None
    meta: GenerationMeta


@router.post("/repurpose", response_model=RepurposeResponse)
async def repurpose_content(
    body: RepurposeRequest,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
    db: AsyncSession = Depends(get_db),
) -> RepurposeResponse:
    """POST /v1/content/repurpose — Repurpose existing content into one or more output formats in a single call."""
    requested = [f for f in body.formats if f in _ALLOWED_FORMATS]
    if not requested:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"At least one valid format required: {sorted(_ALLOWED_FORMATS)}",
        )

    settings = get_settings()
    content_snippet = body.source_content[:3000]

    field_map = {"social_caption": "social_caption", "email": "email_body", "faq": "faq", "short_summary": "short_summary"}
    requested_fields = [field_map[f] for f in requested]
    instructions = "\n".join(f"- {field_map[f]}: {_FORMAT_HINTS[f]}" for f in requested)

    system_prompt = (
        "You repurpose e-commerce content into other formats. Return valid JSON only. "
        "Only populate the fields that were explicitly requested; leave the rest null."
    )
    user_prompt = (
        f"Repurpose the following content titled '{body.source_title}' into the requested formats.\n\n"
        f"Content:\n{content_snippet}\n\n"
        f"Requested fields:\n{instructions}"
    )

    gateway = LLMGateway(settings, tenant.id, api_key_override=get_tenant_anthropic_key(tenant))

    try:
        result = await gateway.complete(
            ModelTier.GENERATE,
            system_prompt,
            user_prompt,
            _RepurposeLLM,
            max_tokens=2048,
        )
    except LLMParseError as exc:
        logger.error("content_repurpose_parse_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="The AI returned an unreadable response. Please try again.",
        )
    except Exception as exc:  # anthropic SDK errors (timeout/connection/status)
        logger.error("content_repurpose_llm_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="Could not reach the AI provider. Please try again in a moment.",
        )

    missing = [f for f in requested_fields if not getattr(result, f)]
    if missing:
        logger.warning(
            "content_repurpose_partial",
            tenant_id=str(tenant.id),
            source_title=body.source_title,
            missing_fields=missing,
        )

    logger.info(
        "content_repurposed",
        tenant_id=str(tenant.id),
        source_title=body.source_title,
        formats=requested,
    )

    usage = gateway.last_usage
    return RepurposeResponse(
        social_caption=result.social_caption,
        email_body=result.email_body,
        faq=result.faq,
        short_summary=result.short_summary,
        meta=GenerationMeta(model=usage["model"], cost_usd=usage["cost_usd"]),
    )
