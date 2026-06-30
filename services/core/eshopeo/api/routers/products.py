from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db, get_tenant
from eshopeo.db.crud.products import get_product_by_id, update_product
from eshopeo.db.models import Tenant

router = APIRouter(prefix="/v1/products", tags=["products"])


class ProductDetailOut(BaseModel):
    id: str
    platform_id: str
    title: str
    description_html: str | None
    price_minor: int
    currency: str
    in_stock: bool
    categories: list[str]
    domain_attributes: dict


class ProductUpdate(BaseModel):
    title: str | None = None
    description_html: str | None = None
    price_minor: int | None = None
    categories: list[str] | None = None
    in_stock: bool | None = None


def _product_detail_out(p) -> ProductDetailOut:
    return ProductDetailOut(
        id=str(p.id),
        platform_id=p.platform_id,
        title=p.title,
        description_html=p.description_html,
        price_minor=p.price_minor,
        currency=p.currency,
        in_stock=p.in_stock,
        categories=p.categories or [],
        domain_attributes=p.domain_attributes or {},
    )


@router.get("/{product_id}", response_model=ProductDetailOut)
async def get_product(
    product_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ProductDetailOut:
    product = await get_product_by_id(db, tenant.id, product_id)
    if product is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Product not found")
    return _product_detail_out(product)


@router.patch("/{product_id}", response_model=ProductDetailOut)
async def patch_product(
    product_id: UUID,
    body: ProductUpdate,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ProductDetailOut:
    updates = body.model_dump(exclude_unset=True)
    if not updates:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail="No fields to update",
        )
    product = await update_product(db, tenant.id, product_id, updates)
    if product is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Product not found")
    await db.commit()
    return _product_detail_out(product)
