import redis.asyncio as aioredis
from datetime import date, datetime, timedelta, timezone

import structlog
from fastapi import APIRouter, Depends, Query
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.config import get_settings
from helix.db.crud.conversations import get_conversation_analytics, get_top_queries, get_top_referenced_products
from helix.db.crud.products import get_embedding_coverage
from helix.db.crud.usage import get_usage_summary
from helix.db.models import Tenant

logger = structlog.get_logger(__name__)
router = APIRouter(prefix="/v1/analytics", tags=["analytics"])


class ModelBreakdown(BaseModel):
    model: str
    calls: int
    cost_usd: float


class UsageSummary(BaseModel):
    tenant_id: str
    period: dict
    total_queries: int
    llm_calls: int
    total_cost_usd: float
    by_model: list[ModelBreakdown]


@router.get("/usage", response_model=UsageSummary)
async def get_usage(
    start_date: date | None = Query(default=None),
    end_date: date | None = Query(default=None),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> UsageSummary:
    today = date.today()
    start = start_date or (today - timedelta(days=30))
    end = end_date or today

    summary = await get_usage_summary(db, tenant.id, start, end)
    return UsageSummary(
        tenant_id=str(tenant.id),
        period={"start": str(start), "end": str(end)},
        **summary,
    )


class QuotaStatus(BaseModel):
    period: str
    used: int
    limit: int
    remaining: int


@router.get("/quota", response_model=QuotaStatus)
async def get_quota_status(
    tenant: Tenant = Depends(get_tenant),
) -> QuotaStatus:
    settings = get_settings()
    period = datetime.now(timezone.utc).strftime("%Y-%m")
    key = f"quota:{tenant.id}:{period}"
    redis_client = aioredis.from_url(str(settings.redis_url), decode_responses=True)
    try:
        used_str = await redis_client.get(key)
        used = int(used_str) if used_str else 0
    except Exception:
        logger.warning("quota_redis_error", tenant_id=str(tenant.id))
        used = 0
    finally:
        await redis_client.aclose()
    limit = settings.default_monthly_query_limit
    return QuotaStatus(
        period=period,
        used=used,
        limit=limit,
        remaining=max(0, limit - used),
    )


class ConversationAnalytics(BaseModel):
    period: dict
    total_conversations: int
    total_messages: int
    avg_messages_per_conversation: float
    feedback_count: int
    feedback_positive_rate: float | None


@router.get("/conversations", response_model=ConversationAnalytics)
async def get_conversation_analytics_endpoint(
    start_date: date | None = Query(default=None),
    end_date: date | None = Query(default=None),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ConversationAnalytics:
    today = date.today()
    start = start_date or (today - timedelta(days=30))
    end = end_date or today

    stats = await get_conversation_analytics(db, tenant.id, start, end)
    return ConversationAnalytics(
        period={"start": str(start), "end": str(end)},
        **stats,
    )


class TopQueryItem(BaseModel):
    query: str
    count: int


class TopQueriesResponse(BaseModel):
    queries: list[TopQueryItem]


@router.get("/top-queries", response_model=TopQueriesResponse)
async def get_top_queries_endpoint(
    limit: int = Query(default=10, ge=1, le=50),
    start_date: date | None = Query(default=None),
    end_date: date | None = Query(default=None),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> TopQueriesResponse:
    queries = await get_top_queries(db, tenant.id, limit=limit, start=start_date, end=end_date)
    return TopQueriesResponse(queries=[TopQueryItem(**q) for q in queries])


class TopReferencedProductItem(BaseModel):
    product_id: str
    count: int


class TopReferencedProductsResponse(BaseModel):
    products: list[TopReferencedProductItem]


@router.get("/products/top", response_model=TopReferencedProductsResponse)
async def get_top_referenced_products_endpoint(
    limit: int = Query(default=10, ge=1, le=50),
    start_date: date | None = Query(default=None),
    end_date: date | None = Query(default=None),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> TopReferencedProductsResponse:
    products = await get_top_referenced_products(
        db, tenant.id, limit=limit, start=start_date, end=end_date
    )
    return TopReferencedProductsResponse(
        products=[TopReferencedProductItem(**p) for p in products]
    )


class EmbeddingCoverage(BaseModel):
    total: int
    embedded: int
    missing: int
    coverage_rate: float


@router.get("/products/embedding-coverage", response_model=EmbeddingCoverage)
async def get_embedding_coverage_endpoint(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> EmbeddingCoverage:
    coverage = await get_embedding_coverage(db, tenant.id)
    return EmbeddingCoverage(**coverage)
