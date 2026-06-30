"""Review synthesis router — summarises product reviews into structured insights."""

from __future__ import annotations

import json as _json
import re

import anthropic
import structlog
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db, get_tenant
from eshopeo.config import get_settings
from eshopeo.db.models import Tenant

logger = structlog.get_logger(__name__)

router = APIRouter(prefix="/v1/reviews", tags=["reviews"])


class ReviewItem(BaseModel):
    rating: int
    content: str


class ReviewSynthesisRequest(BaseModel):
    product_title: str
    reviews: list[ReviewItem]


class ReviewSynthesisResponse(BaseModel):
    summary: str
    pros: list[str]
    cons: list[str]
    sentiment: str
    avg_rating: float


@router.post("/synthesise", response_model=ReviewSynthesisResponse)
async def synthesise_reviews(
    body: ReviewSynthesisRequest,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ReviewSynthesisResponse:
    """Synthesise a list of product reviews into a structured summary."""
    if not body.reviews:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="At least one review is required.",
        )

    settings = get_settings()
    client = anthropic.AsyncAnthropic(api_key=settings.anthropic_api_key.get_secret_value())

    avg_rating = round(sum(r.rating for r in body.reviews) / len(body.reviews), 1)

    # Build a compact review block (max 30 reviews, 200 chars each).
    review_lines = []
    for r in body.reviews[:30]:
        snippet = r.content[:200].replace("\n", " ")
        review_lines.append(f"[{r.rating}/5] {snippet}")
    reviews_text = "\n".join(review_lines)

    prompt = (
        f"Analyse these customer reviews for '{body.product_title}' and return a JSON object.\n\n"
        f"Reviews:\n{reviews_text}\n\n"
        f"Return ONLY valid JSON with this exact shape:\n"
        f'{{"summary":"<2-3 sentence overview>","pros":["<up to 4 key positives>"],'
        f'"cons":["<up to 3 key negatives>"],"sentiment":"<positive|mixed|negative>"}}'
    )

    response = await client.messages.create(
        model="claude-haiku-4-5-20251001",
        max_tokens=400,
        messages=[{"role": "user", "content": prompt}],
    )

    raw = response.content[0].text.strip()  # type: ignore[index]

    try:
        data = _json.loads(raw)
    except _json.JSONDecodeError:
        match = re.search(r"\{.*\}", raw, re.DOTALL)
        if not match:
            raise HTTPException(
                status_code=status.HTTP_502_BAD_GATEWAY,
                detail=f"Model returned non-JSON: {raw[:200]}",
            )
        data = _json.loads(match.group())

    logger.info(
        "reviews_synthesised",
        tenant_id=str(tenant.id),
        product_title=body.product_title,
        review_count=len(body.reviews),
    )

    return ReviewSynthesisResponse(
        summary=data.get("summary", ""),
        pros=data.get("pros", []),
        cons=data.get("cons", []),
        sentiment=data.get("sentiment", "mixed"),
        avg_rating=avg_rating,
    )
