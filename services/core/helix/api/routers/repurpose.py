"""Content repurposing router — uses Claude Sonnet to generate multiple formats."""

from __future__ import annotations

import json as _json
import re
from typing import Optional

import anthropic
import structlog
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant, check_ai_op_quota, get_tenant_anthropic_key
from helix.config import get_settings
from helix.db.models import Tenant

logger = structlog.get_logger(__name__)

router = APIRouter(prefix="/v1/content", tags=["content"])

_ALLOWED_FORMATS = {"social_caption", "email", "faq", "short_summary"}


class RepurposeRequest(BaseModel):
    source_title: str
    source_content: str
    formats: list[str]  # social_caption, email, faq, short_summary
    pack_id: str = "general"


class RepurposeResponse(BaseModel):
    social_caption: Optional[str] = None
    email_body: Optional[str] = None
    faq: Optional[list[dict]] = None
    short_summary: Optional[str] = None


@router.post("/repurpose", response_model=RepurposeResponse)
async def repurpose_content(
    body: RepurposeRequest,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
    db: AsyncSession = Depends(get_db),
) -> RepurposeResponse:
    """Repurpose existing content into one or more output formats in a single call."""
    requested = [f for f in body.formats if f in _ALLOWED_FORMATS]
    if not requested:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"At least one valid format required: {sorted(_ALLOWED_FORMATS)}",
        )

    settings = get_settings()
    client = anthropic.AsyncAnthropic(api_key=get_tenant_anthropic_key(tenant) or settings.anthropic_api_key.get_secret_value())

    content_snippet = body.source_content[:3000]

    format_instructions: list[str] = []
    if "social_caption" in requested:
        format_instructions.append(
            '"social_caption": "<engaging social media caption, max 280 chars, include 2-3 relevant hashtags>"'
        )
    if "email" in requested:
        format_instructions.append(
            '"email_body": "<email newsletter body, 150-200 words, with subject hint in first line>"'
        )
    if "faq" in requested:
        format_instructions.append(
            '"faq": [{"question": "...", "answer": "..."}, ...] (3-5 FAQ pairs)'
        )
    if "short_summary" in requested:
        format_instructions.append(
            '"short_summary": "<concise 2-3 sentence summary suitable for a featured snippet>"'
        )

    formats_json = ",\n  ".join(format_instructions)
    prompt = (
        f"Repurpose the following content titled '{body.source_title}' into the requested formats.\n\n"
        f"Content:\n{content_snippet}\n\n"
        f"Return ONLY a JSON object with these fields:\n{{\n  {formats_json}\n}}\n\n"
        f"Return raw JSON only — no markdown code fences or explanation."
    )

    response = await client.messages.create(
        model="claude-sonnet-4-6",
        max_tokens=2048,
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
        "content_repurposed",
        tenant_id=str(tenant.id),
        source_title=body.source_title,
        formats=requested,
    )

    return RepurposeResponse(
        social_caption=data.get("social_caption"),
        email_body=data.get("email_body"),
        faq=data.get("faq"),
        short_summary=data.get("short_summary"),
    )
