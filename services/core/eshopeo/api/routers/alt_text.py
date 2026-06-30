"""Alt-text generation router — uses Claude Haiku for cost-efficient alt text."""

from __future__ import annotations

import anthropic
import structlog
from fastapi import APIRouter, Depends
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db, get_tenant, check_ai_op_quota, get_tenant_anthropic_key
from eshopeo.config import get_settings
from eshopeo.db.models import Tenant

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


class AltTextResponse(BaseModel):
    alt_text: str


@router.post("/generate", response_model=AltTextResponse)
async def generate_alt_text(
    body: AltTextRequest,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
    db: AsyncSession = Depends(get_db),
) -> AltTextResponse:
    """Generate concise SEO alt text for an image using Claude Haiku."""
    settings = get_settings()
    client = anthropic.AsyncAnthropic(api_key=get_tenant_anthropic_key(tenant) or settings.anthropic_api_key.get_secret_value())

    pack_label = _PACK_LABELS.get(body.pack_id, "retail")
    prompt = (
        f"Write a concise alt text (max 120 chars) for an image titled "
        f"'{body.image_title}' on a {pack_label} website. "
        f"Return only the alt text, no quotes or explanation."
    )

    response = await client.messages.create(
        model="claude-haiku-4-5-20251001",
        max_tokens=60,
        messages=[{"role": "user", "content": prompt}],
    )

    alt_text = response.content[0].text.strip()  # type: ignore[index]

    logger.info(
        "alt_text_generated",
        tenant_id=str(tenant.id),
        image_title=body.image_title,
        pack_id=body.pack_id,
    )

    return AltTextResponse(alt_text=alt_text)
