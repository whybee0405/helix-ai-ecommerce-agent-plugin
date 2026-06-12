from typing import Annotated
from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.db.crud.customers import count_customers, get_customer_by_id, list_customers
from helix.db.models import Tenant

router = APIRouter(prefix="/v1/customers", tags=["customers"])


class CustomerOut(BaseModel):
    id: str
    platform_id: str
    email_hash: str
    profile: dict
    created_at: str


class CustomerListResponse(BaseModel):
    customers: list[CustomerOut]
    total: int
    limit: int
    offset: int


@router.get("", response_model=CustomerListResponse)
async def list_customers_endpoint(
    limit: Annotated[int, Query(ge=1, le=100)] = 20,
    offset: Annotated[int, Query(ge=0)] = 0,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> CustomerListResponse:
    customers = await list_customers(db, tenant.id, limit=limit, offset=offset)
    total = await count_customers(db, tenant.id)
    return CustomerListResponse(
        customers=[
            CustomerOut(
                id=str(c.id),
                platform_id=c.platform_id,
                email_hash=c.email_hash,
                profile=c.profile or {},
                created_at=c.created_at.isoformat(),
            )
            for c in customers
        ],
        total=total,
        limit=limit,
        offset=offset,
    )


@router.get("/{customer_id}", response_model=CustomerOut)
async def get_customer_endpoint(
    customer_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> CustomerOut:
    customer = await get_customer_by_id(db, customer_id, tenant.id)
    if customer is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Customer not found")
    return CustomerOut(
        id=str(customer.id),
        platform_id=customer.platform_id,
        email_hash=customer.email_hash,
        profile=customer.profile or {},
        created_at=customer.created_at.isoformat(),
    )
