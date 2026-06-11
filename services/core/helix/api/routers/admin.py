from datetime import datetime, timezone
from typing import Annotated

from fastapi import APIRouter, Depends, Header, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db
from helix.config import get_settings
from helix.db.crud.admin import get_platform_stats

router = APIRouter(prefix="/v1/admin", tags=["admin"])


class PlatformStats(BaseModel):
    total_tenants: int
    total_products: int
    total_customers: int
    queries_this_month: int
    cost_this_month_usd: float


def _auth_provision(x_helix_provision_key: Annotated[str | None, Header()] = None) -> str:
    settings = get_settings()
    if x_helix_provision_key != settings.provision_key.get_secret_value():
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid provision key")
    return x_helix_provision_key


@router.get("/stats", response_model=PlatformStats)
async def admin_stats(
    _: str = Depends(_auth_provision), db: AsyncSession = Depends(get_db)
) -> PlatformStats:
    today = datetime.now(timezone.utc).date()
    month_start = datetime(today.year, today.month, 1, tzinfo=timezone.utc)
    month_end = datetime.now(timezone.utc)
    stats = await get_platform_stats(db, month_start, month_end)
    return PlatformStats(**stats)
