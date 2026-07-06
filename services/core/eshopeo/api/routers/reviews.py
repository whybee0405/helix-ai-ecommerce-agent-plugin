"""
Review synthesis router — summarises product reviews into structured insights.

POST /v1/reviews/synthesise — synthesise a product's reviews into summary/pros/cons
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

router = APIRouter(prefix="/v1/reviews", tags=["reviews"])


class ReviewItem(BaseModel):
    rating: int
    content: str


class ReviewSynthesisRequest(BaseModel):
    product_title: str
    reviews: list[ReviewItem]


class _ReviewSynthesisLLM(BaseModel):
    summary: str
    pros: list[str]
    cons: list[str]
    sentiment: str


class ReviewSynthesisResponse(BaseModel):
    summary: str
    pros: list[str]
    cons: list[str]
    sentiment: str
    avg_rating: float
    meta: GenerationMeta


@router.post("/synthesise", response_model=ReviewSynthesisResponse)
async def synthesise_reviews(
    body: ReviewSynthesisRequest,
    tenant: Tenant = Depends(get_tenant),
    _: Tenant = Depends(check_ai_op_quota),
    db: AsyncSession = Depends(get_db),
) -> ReviewSynthesisResponse:
    """POST /v1/reviews/synthesise — Synthesise a list of product reviews into a structured summary."""
    if not body.reviews:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="At least one review is required.",
        )

    settings = get_settings()
    avg_rating = round(sum(r.rating for r in body.reviews) / len(body.reviews), 1)

    # Build a compact review block (max 30 reviews, 200 chars each).
    review_lines = []
    for r in body.reviews[:30]:
        snippet = r.content[:200].replace("\n", " ")
        review_lines.append(f"[{r.rating}/5] {snippet}")
    reviews_text = "\n".join(review_lines)

    system_prompt = (
        "You analyse customer reviews for an e-commerce store and return a structured summary. "
        "Return valid JSON only."
    )
    user_prompt = (
        f"Analyse these customer reviews for '{body.product_title}'.\n\n"
        f"Reviews:\n{reviews_text}\n\n"
        f"summary: a 2-3 sentence overview. pros: up to 4 key positives. "
        f"cons: up to 3 key negatives. sentiment: one of positive|mixed|negative."
    )

    gateway = LLMGateway(settings, tenant.id, api_key_override=get_tenant_anthropic_key(tenant))

    try:
        result = await gateway.complete(
            ModelTier.CLASSIFY,
            system_prompt,
            user_prompt,
            _ReviewSynthesisLLM,
            max_tokens=400,
        )
    except LLMParseError as exc:
        logger.error("reviews_synthesis_parse_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="The AI returned an unreadable response. Please try again.",
        )
    except Exception as exc:  # anthropic SDK errors (timeout/connection/status)
        logger.error("reviews_synthesis_llm_failure", tenant_id=str(tenant.id), error=str(exc))
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail="Could not reach the AI provider. Please try again in a moment.",
        )

    logger.info(
        "reviews_synthesised",
        tenant_id=str(tenant.id),
        product_title=body.product_title,
        review_count=len(body.reviews),
    )

    usage = gateway.last_usage
    return ReviewSynthesisResponse(
        summary=result.summary,
        pros=result.pros,
        cons=result.cons,
        sentiment=result.sentiment,
        avg_rating=avg_rating,
        meta=GenerationMeta(model=usage["model"], cost_usd=usage["cost_usd"]),
    )
