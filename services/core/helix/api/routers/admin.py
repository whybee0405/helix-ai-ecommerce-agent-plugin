from datetime import datetime, timezone
from typing import Annotated
from uuid import UUID

from fastapi import APIRouter, Depends, Header, HTTPException, Query, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db
from helix.config import get_settings
from helix.db.crud.admin import get_platform_stats
from helix.db.crud.tenants import count_tenants, get_tenant_by_id, list_tenants

router = APIRouter(prefix="/v1/admin", tags=["admin"])


class PlatformStats(BaseModel):
    total_tenants: int
    total_products: int
    total_customers: int
    queries_this_month: int
    cost_this_month_usd: float


class TenantOut(BaseModel):
    id: str
    name: str
    platform: str
    store_url: str
    public_key: str
    pack_id: str | None
    created_at: str


class TenantListResponse(BaseModel):
    tenants: list[TenantOut]
    total: int
    limit: int
    offset: int


def _tenant_out(tenant) -> TenantOut:
    return TenantOut(
        id=str(tenant.id),
        name=tenant.name,
        platform=tenant.platform,
        store_url=tenant.store_url,
        public_key=str(tenant.public_key),
        pack_id=tenant.pack_id,
        created_at=tenant.created_at.isoformat(),
    )


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


@router.get("/tenants", response_model=TenantListResponse)
async def list_tenants_endpoint(
    limit: Annotated[int, Query(ge=1, le=100)] = 20,
    offset: Annotated[int, Query(ge=0)] = 0,
    _: str = Depends(_auth_provision),
    db: AsyncSession = Depends(get_db),
) -> TenantListResponse:
    tenants = await list_tenants(db, limit=limit, offset=offset)
    total = await count_tenants(db)
    return TenantListResponse(
        tenants=[_tenant_out(t) for t in tenants],
        total=total,
        limit=limit,
        offset=offset,
    )


@router.get("/tenants/{tenant_id}", response_model=TenantOut)
async def get_tenant_endpoint(
    tenant_id: UUID,
    _: str = Depends(_auth_provision),
    db: AsyncSession = Depends(get_db),
) -> TenantOut:
    tenant = await get_tenant_by_id(db, tenant_id)
    if tenant is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Tenant not found")
    return _tenant_out(tenant)
