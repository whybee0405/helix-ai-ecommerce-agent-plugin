"""
Analytics router — dashboard usage, conversation, and commerce metrics.

GET /v1/analytics/usage                          — AI usage/cost summary
GET /v1/analytics/quota                           — current quota status
GET /v1/analytics/conversations                   — conversation volume over time
GET /v1/analytics/top-queries                      — most common customer queries
GET /v1/analytics/products/top                     — top-referenced products
GET /v1/analytics/products/embedding-coverage      — % of catalog embedded
GET /v1/analytics/customers/segments               — customer segmentation
GET /v1/analytics/orders                           — order volume over time
GET /v1/analytics/orders/by-status                 — order breakdown by status
GET /v1/analytics/products/inventory                — inventory-level analytics
"""

import redis.asyncio as aioredis
from datetime import date, datetime, timedelta, timezone

import structlog
from fastapi import APIRouter, Depends, Query
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db, get_tenant
from eshopeo.config import get_settings
from eshopeo.db.crud.conversations import get_conversation_analytics, get_top_queries, get_top_referenced_products
from eshopeo.db.crud.orders import get_order_analytics, get_orders_by_status
from eshopeo.db.crud.customers import get_customer_segments
from eshopeo.db.crud.products import get_embedding_coverage, get_inventory_snapshot
from eshopeo.db.crud.usage import get_usage_summary
from eshopeo.db.models import Tenant

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
    """GET /v1/analytics/usage."""
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
    """GET /v1/analytics/quota."""
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
    """GET /v1/analytics/conversations."""
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
    """GET /v1/analytics/top-queries."""
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
    """GET /v1/analytics/products/top."""
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
    """GET /v1/analytics/products/embedding-coverage."""
    coverage = await get_embedding_coverage(db, tenant.id)
    return EmbeddingCoverage(**coverage)


class CustomerSegmentItem(BaseModel):
    skin_type: str
    count: int


class CustomerSegmentsResponse(BaseModel):
    segments: list[CustomerSegmentItem]


@router.get("/customers/segments", response_model=CustomerSegmentsResponse)
async def get_customer_segments_endpoint(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> CustomerSegmentsResponse:
    """GET /v1/analytics/customers/segments."""
    segments = await get_customer_segments(db, tenant.id)
    return CustomerSegmentsResponse(
        segments=[CustomerSegmentItem(**s) for s in segments]
    )


class OrderAnalyticsResponse(BaseModel):
    period: dict
    total_orders: int
    total_revenue_minor: int
    avg_order_value_minor: int


@router.get("/orders", response_model=OrderAnalyticsResponse)
async def get_order_analytics_endpoint(
    start_date: date | None = Query(default=None),
    end_date: date | None = Query(default=None),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> OrderAnalyticsResponse:
    """GET /v1/analytics/orders."""
    today = date.today()
    effective_start = start_date or (today - timedelta(days=30))
    effective_end = end_date or today
    analytics = await get_order_analytics(
        db, tenant.id, start=effective_start, end=effective_end
    )
    return OrderAnalyticsResponse(
        period={"start": effective_start.isoformat(), "end": effective_end.isoformat()},
        **analytics,
    )


class OrderStatusItem(BaseModel):
    status: str
    count: int
    total_revenue_minor: int


class OrdersByStatusResponse(BaseModel):
    statuses: list[OrderStatusItem]


@router.get("/orders/by-status", response_model=OrdersByStatusResponse)
async def get_orders_by_status_endpoint(
    start_date: date | None = Query(default=None),
    end_date: date | None = Query(default=None),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> OrdersByStatusResponse:
    """GET /v1/analytics/orders/by-status."""
    statuses = await get_orders_by_status(
        db, tenant.id, start=start_date, end=end_date
    )
    return OrdersByStatusResponse(
        statuses=[OrderStatusItem(**s) for s in statuses]
    )


class InventorySnapshot(BaseModel):
    total: int
    in_stock: int
    out_of_stock: int
    in_stock_rate: float


@router.get("/products/inventory", response_model=InventorySnapshot)
async def get_inventory_snapshot_endpoint(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> InventorySnapshot:
    """GET /v1/analytics/products/inventory."""
    snapshot = await get_inventory_snapshot(db, tenant.id)
    return InventorySnapshot(**snapshot)
