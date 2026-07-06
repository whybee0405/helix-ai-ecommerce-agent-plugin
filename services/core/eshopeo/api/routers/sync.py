"""
WooCommerce sync router — inbound bulk sync calls from the WP plugin.

POST  /v1/sync/products                              — bulk upsert products
POST  /v1/sync/customers                              — bulk upsert customers
PATCH /v1/sync/customers/{platform_id}/profile        — update a customer's domain profile
POST  /v1/sync/orders                                 — bulk upsert orders
"""

from datetime import datetime, timezone
from uuid import uuid4

import jsonschema
import structlog
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.deps import get_db, get_tenant
from eshopeo.connectors.models import CanonicalCustomer, CanonicalOrder, CanonicalProduct
from eshopeo.db.crud.customers import get_customer_by_platform_id, update_customer_profile, upsert_customer
from eshopeo.db.crud.orders import get_customer_id_by_platform_id, upsert_order
from eshopeo.db.crud.products import delete_product, upsert_product
from eshopeo.db.models import Customer, Order, Product, Tenant
from eshopeo.packs.registry import get_pack_for_tenant
from eshopeo.workers.tasks.embedding import embed_product

logger = structlog.get_logger(__name__)
router = APIRouter(prefix="/v1/sync", tags=["sync"])


class SyncRequest(BaseModel):
    products: list[CanonicalProduct]


class SyncResponse(BaseModel):
    synced: int
    failed: int
    errors: list[str]


@router.post("/products", response_model=SyncResponse)
async def sync_products(
    body: SyncRequest,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> SyncResponse:
    """POST /v1/sync/products."""
    pack = get_pack_for_tenant(tenant)
    validator = jsonschema.Draft7Validator(pack.product_schema)

    synced = 0
    failed = 0
    errors: list[str] = []

    for cp in body.products:
        try:
            if cp.deleted:
                await delete_product(db, tenant.id, cp.platform_id)
                synced += 1
                continue

            validation_errors = list(validator.iter_errors(cp.domain_attributes))
            if validation_errors:
                msg = f"product {cp.platform_id}: {validation_errors[0].message}"
                errors.append(msg)
                failed += 1
                continue

            product = Product(
                tenant_id=tenant.id,
                platform_id=cp.platform_id,
                title=cp.title,
                description_html=cp.description_html,
                price_minor=cp.price_minor,
                currency=cp.currency,
                images=cp.images,
                categories=cp.categories,
                in_stock=cp.in_stock,
                domain_attributes=cp.domain_attributes,
                updated_at=datetime.now(timezone.utc),
            )
            saved = await upsert_product(db, product)
            embed_product.delay(str(tenant.id), str(saved.id))
            synced += 1
        except Exception as exc:
            logger.warning("sync_product_error", platform_id=cp.platform_id, error=str(exc))
            errors.append(f"product {cp.platform_id}: {exc}")
            failed += 1

    await db.commit()
    return SyncResponse(synced=synced, failed=failed, errors=errors)


class CustomerSyncRequest(BaseModel):
    customers: list[CanonicalCustomer]


class CustomerSyncResponse(BaseModel):
    synced: int
    failed: int
    errors: list[str]


class CustomerProfilePatch(BaseModel):
    profile: dict


@router.post("/customers", response_model=CustomerSyncResponse)
async def sync_customers(
    body: CustomerSyncRequest,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> CustomerSyncResponse:
    """POST /v1/sync/customers."""
    pack = get_pack_for_tenant(tenant)
    profile_validator = jsonschema.Draft7Validator(pack.profile_schema)

    synced = 0
    failed = 0
    errors: list[str] = []

    for cc in body.customers:
        try:
            validation_errors = list(profile_validator.iter_errors(cc.profile))
            if validation_errors:
                errors.append(f"customer {cc.platform_id}: {validation_errors[0].message}")
                failed += 1
                continue

            customer = Customer(
                tenant_id=tenant.id,
                platform_id=cc.platform_id,
                email_hash=cc.email_hash,
                profile=cc.profile,
            )
            await upsert_customer(db, customer)
            synced += 1
        except Exception as exc:
            logger.warning("sync_customer_error", platform_id=cc.platform_id, error=str(exc))
            errors.append(f"customer {cc.platform_id}: {exc}")
            failed += 1

    await db.commit()
    return CustomerSyncResponse(synced=synced, failed=failed, errors=errors)


@router.patch("/customers/{platform_id}/profile")
async def patch_customer_profile(
    platform_id: str,
    body: CustomerProfilePatch,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> dict:
    """PATCH /v1/sync/customers/{platform_id}/profile."""
    customer = await get_customer_by_platform_id(db, tenant.id, platform_id)
    if customer is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Customer not found")
    new_profile = {**(customer.profile or {}), **body.profile}
    updated = await update_customer_profile(db, customer, new_profile)
    await db.commit()
    return {"customer_id": str(updated.id), "platform_id": updated.platform_id}


class OrderSyncRequest(BaseModel):
    orders: list[CanonicalOrder]


class OrderSyncResponse(BaseModel):
    synced: int
    failed: int
    errors: list[str]


@router.post("/orders", response_model=OrderSyncResponse)
async def sync_orders(
    body: OrderSyncRequest,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> OrderSyncResponse:
    """POST /v1/sync/orders."""
    synced = 0
    failed = 0
    errors: list[str] = []

    for co in body.orders:
        try:
            customer_id = None
            if co.customer_platform_id:
                customer_id = await get_customer_id_by_platform_id(
                    db, tenant.id, co.customer_platform_id
                )
            order = Order(
                tenant_id=tenant.id,
                platform_id=co.platform_id,
                customer_id=customer_id,
                total_minor=co.total_minor,
                currency=co.currency,
                status=co.status,
                line_items=co.line_items,
                placed_at=co.placed_at,
            )
            await upsert_order(db, order)
            synced += 1
        except Exception as exc:
            logger.warning("sync_order_error", platform_id=co.platform_id, error=str(exc))
            errors.append(f"order {co.platform_id}: {exc}")
            failed += 1

    await db.commit()
    return OrderSyncResponse(synced=synced, failed=failed, errors=errors)
