from uuid import uuid4

import jsonschema
import structlog
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.connectors.models import CanonicalCustomer, CanonicalProduct
from helix.db.crud.customers import upsert_customer
from helix.db.crud.products import delete_product, upsert_product
from helix.db.models import Customer, Product, Tenant
from helix.packs.registry import get_pack_for_tenant
from helix.workers.tasks.embedding import embed_product

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


@router.post("/customers", response_model=CustomerSyncResponse)
async def sync_customers(
    body: CustomerSyncRequest,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> CustomerSyncResponse:
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
