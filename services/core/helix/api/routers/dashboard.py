from datetime import datetime, timezone

from fastapi import APIRouter, Depends
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.config import get_settings
from helix.db.crud.dashboard import get_dashboard_summary
from helix.db.models import Tenant

router = APIRouter(prefix="/v1/dashboard", tags=["dashboard"])


class DashboardOut(BaseModel):
    product_count: int
    customer_count: int
    conversations_this_month: int
    pending_drafts: int
    queries_this_month: int
    cost_this_month_usd: float
    quota_limit: int
    quota_used: int


@router.get("", response_model=DashboardOut)
async def get_dashboard(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> DashboardOut:
    settings = get_settings()
    now = datetime.now(timezone.utc)
    year, mon = now.year, now.month
    month_start = datetime(year, mon, 1, tzinfo=timezone.utc)
    next_mon, next_year = (mon + 1, year) if mon < 12 else (1, year + 1)
    month_end = datetime(next_year, next_mon, 1, tzinfo=timezone.utc)

    summary = await get_dashboard_summary(db, tenant.id, month_start, month_end)
    return DashboardOut(
        **summary,
        quota_limit=settings.default_monthly_query_limit,
        quota_used=summary["queries_this_month"],
    )
