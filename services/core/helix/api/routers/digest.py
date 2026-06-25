"""Weekly digest router — generates a friendly site summary email via Claude Haiku."""

from __future__ import annotations

import anthropic
import structlog
from fastapi import APIRouter, Depends
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant, check_ai_op_quota, get_tenant_anthropic_key
from helix.config import get_settings
from helix.db.models import Tenant

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


class DigestResponse(BaseModel):
    subject: str
    body: str


@router.post("/generate", response_model=DigestResponse)
async def generate_digest(
    body: DigestStats,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
    db: AsyncSession = Depends(get_db),
) -> DigestResponse:
    """Generate a 150-word weekly site digest email."""
    settings = get_settings()
    client = anthropic.AsyncAnthropic(api_key=get_tenant_anthropic_key(tenant) or settings.anthropic_api_key.get_secret_value())

    errors_section = ""
    if body.top_errors:
        errors_section = "\nTop errors: " + "; ".join(body.top_errors[:3])

    prompt = (
        f"Write a friendly, concise weekly digest email (about 150 words) for the website "
        f"'{body.site_name}' covering the past 7 days.\n\n"
        f"Stats:\n"
        f"- New posts published: {body.new_posts}\n"
        f"- New orders: {body.new_orders}\n"
        f"- New leads: {body.new_leads}\n"
        f"- Broken links found: {body.broken_links}\n"
        f"- Uptime: {body.uptime_pct}%"
        f"{errors_section}\n\n"
        f"Tone: professional but warm. Highlight wins, flag issues, suggest one action.\n"
        f"Start with a short email subject line on its own line prefixed with 'Subject: ', "
        f"then a blank line, then the email body."
    )

    response = await client.messages.create(
        model="claude-haiku-4-5-20251001",
        max_tokens=400,
        messages=[{"role": "user", "content": prompt}],
    )

    text = response.content[0].text.strip()  # type: ignore[index]

    # Parse subject and body.
    subject = f"Weekly Site Digest — {body.site_name}"
    email_body = text

    lines = text.split("\n", 2)
    if lines and lines[0].lower().startswith("subject:"):
        subject = lines[0][len("subject:"):].strip()
        email_body = "\n".join(lines[1:]).lstrip("\n")

    logger.info(
        "digest_generated",
        tenant_id=str(tenant.id),
        site_name=body.site_name,
    )

    return DigestResponse(subject=subject, body=email_body)
