"""
Alt-text generation router — uses Claude Haiku for cost-efficient alt text.

POST /v1/alt-text/generate — generate SEO alt text for one image
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

router = APIRouter(prefix="/v1/alt-text", tags=["alt-text"])

_PACK_LABELS: dict[str, str] = {
    "kbeauty": "beauty/skincare",
    "automotive": "automotive dealership",
    "general": "retail",
}


class AltTextRequest(BaseModel):
    image_title: str
    image_url: str = ""
    pack_id: str = "general"


class _AltTextLLM(BaseModel):
    alt_text: str


class AltTextResponse(BaseModel):
    alt_text: str
    meta: GenerationMeta


@router.post("/generate", response_model=AltTextResponse)
async def generate_alt_text(
    body: AltTextRequest,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
    db: AsyncSession = Depends(get_db),
) -> AltTextResponse:
    """POST /v1/alt-text/generate — Generate concise SEO alt text for an image using Claude Haiku."""
    settings = get_settings()
    pack_label = _PACK_LABELS.get(body.pack_id, "retail")
    system_prompt = (
        "You write concise, accurate SEO alt text for e-commerce product images. "
        "Return valid JSON only."
    )
    user_prompt = (
        f"Write alt text (max 120 chars) for an image titled '{body.image_title}' "
        f"on a {pack_label} website."
    )

    gateway = LLMGateway(settings, tenant.id, api_key_override=get_tenant_anthropic_key(tenant))

    try:
        result = await gateway.complete(
            ModelTier.CLASSIFY,
            system_prompt,
            user_prompt,
            _AltTextLLM,
            max_tokens=60,
        )
    except LLMParseError as exc:
        logger.error("alt_text_parse_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="The AI returned an unreadable response. Please try again.",
        )
    except Exception as exc:  # anthropic SDK errors (timeout/connection/status)
        logger.error("alt_text_llm_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="Could not reach the AI provider. Please try again in a moment.",
        )

    alt_text = result.alt_text.strip()
    if not alt_text:
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="The AI returned empty alt text. Please try again.",
        )

    logger.info(
        "alt_text_generated",
        tenant_id=str(tenant.id),
        image_title=body.image_title,
        pack_id=body.pack_id,
    )

    usage = gateway.last_usage
    return AltTextResponse(
        alt_text=alt_text,
        meta=GenerationMeta(model=usage["model"], cost_usd=usage["cost_usd"]),
    )
