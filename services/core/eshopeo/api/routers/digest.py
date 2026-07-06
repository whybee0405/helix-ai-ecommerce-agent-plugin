"""
Weekly digest router — generates a friendly site summary email via Claude Haiku.

POST /v1/digest/generate — generate a weekly site-health digest email (subject + body)
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

router = APIRouter(prefix="/v1/digest", tags=["digest"])


class DigestStats(BaseModel):
    site_name: str
    new_posts: int = 0
    new_orders: int = 0
    new_leads: int = 0
    broken_links: int = 0
    uptime_pct: float = 100.0
    top_errors: list[str] = []


class _DigestLLM(BaseModel):
    subject: str
    body: str


class DigestResponse(BaseModel):
    subject: str
    body: str
    meta: GenerationMeta


@router.post("/generate", response_model=DigestResponse)
async def generate_digest(
    body: DigestStats,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
    db: AsyncSession = Depends(get_db),
) -> DigestResponse:
    """POST /v1/digest/generate — Generate a 150-word weekly site digest email."""
    settings = get_settings()

    errors_section = ""
    if body.top_errors:
        errors_section = "\nTop errors: " + "; ".join(body.top_errors[:3])

    system_prompt = (
        "You write friendly, concise weekly site-health digest emails for store owners. "
        "Return valid JSON only."
    )
    user_prompt = (
        f"Write a weekly digest email (about 150 words) for the website "
        f"'{body.site_name}' covering the past 7 days.\n\n"
        f"Stats:\n"
        f"- New posts published: {body.new_posts}\n"
        f"- New orders: {body.new_orders}\n"
        f"- New leads: {body.new_leads}\n"
        f"- Broken links found: {body.broken_links}\n"
        f"- Uptime: {body.uptime_pct}%"
        f"{errors_section}\n\n"
        f"Tone: professional but warm. Highlight wins, flag issues, suggest one action. "
        f"subject: a short email subject line. body: the email body text."
    )

    gateway = LLMGateway(settings, tenant.id, api_key_override=get_tenant_anthropic_key(tenant))

    try:
        result = await gateway.complete(
            ModelTier.CLASSIFY,
            system_prompt,
            user_prompt,
            _DigestLLM,
            max_tokens=400,
        )
    except LLMParseError as exc:
        logger.error("digest_parse_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="The AI returned an unreadable response. Please try again.",
        )
    except Exception as exc:  # anthropic SDK errors (timeout/connection/status)
        logger.error("digest_llm_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="Could not reach the AI provider. Please try again in a moment.",
        )

    subject = result.subject.strip() or f"Weekly Site Digest — {body.site_name}"
    email_body = result.body.strip()
    if not email_body:
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="The AI returned an empty digest. Please try again.",
        )

    logger.info(
        "digest_generated",
        tenant_id=str(tenant.id),
        site_name=body.site_name,
    )

    usage = gateway.last_usage
    return DigestResponse(
        subject=subject,
        body=email_body,
        meta=GenerationMeta(model=usage["model"], cost_usd=usage["cost_usd"]),
    )
