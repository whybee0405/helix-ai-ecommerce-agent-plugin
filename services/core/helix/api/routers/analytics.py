import redis.asyncio as aioredis
from datetime import date, datetime, timedelta, timezone

import structlog
from fastapi import APIRouter, Depends, Query
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.config import get_settings
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
