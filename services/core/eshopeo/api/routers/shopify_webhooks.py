"""
Shopify webhooks router — inbound product/order sync events from Shopify.

POST /v1/webhooks/shopify/products  — Shopify product create/update/delete webhook
POST /v1/webhooks/shopify/orders    — Shopify order create/update webhook
"""

import json
import structlog
import jsonschema
from datetime import datetime, timezone
from uuid import UUID

from fastapi import APIRouter, Depends, Header, HTTPException, Request, status
from sqlalchemy.ext.asyncio import AsyncSession

from eshopeo.api.auth.crypto import decrypt_credentials
from eshopeo.api.deps import get_db
from eshopeo.config import get_settings
from eshopeo.connectors.shopify import translate_shopify_product, translate_shopify_order, verify_shopify_webhook
from eshopeo.db.crud.products import delete_product, upsert_product
from eshopeo.db.crud.orders import upsert_order, get_customer_id_by_platform_id
from eshopeo.db.crud.tenants import get_tenant_by_id
from eshopeo.db.models import Order, Product
from eshopeo.packs.registry import get_pack_for_tenant
from eshopeo.workers.tasks.embedding import embed_product

logger = structlog.get_logger(__name__)
router = APIRouter(prefix="/v1/webhooks/shopify", tags=["shopify"])


@router.post("/products")
async def shopify_product_webhook(
    request: Request,
    x_eshopeo_tenant_id: str | None = Header(default=None),
    x_shopify_hmac_sha256: str | None = Header(default=None),
    db: AsyncSession = Depends(get_db),
):
    """POST /v1/webhooks/shopify/products."""
    if x_eshopeo_tenant_id is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Missing tenant ID")
    if x_shopify_hmac_sha256 is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Missing HMAC header")

    try:
        tenant_id = UUID(x_eshopeo_tenant_id)
    except ValueError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid tenant ID")

    tenant = await get_tenant_by_id(db, tenant_id)
    if tenant is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Unknown tenant")

    settings = get_settings()
    creds = decrypt_credentials(tenant.credentials_enc, settings.credential_encryption_key.get_secret_value())
    webhook_secret = creds.get("webhook_secret", "")

    body = await request.body()
    if not verify_shopify_webhook(body, x_shopify_hmac_sha256, webhook_secret):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid webhook signature")

    try:
        payload = json.loads(body)
    except json.JSONDecodeError:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid JSON payload")

    cp = translate_shopify_product(payload, tenant_id)

    if cp.deleted:
        await delete_product(db, tenant_id, cp.platform_id)
    else:
        pack = get_pack_for_tenant(tenant)
        errors = list(jsonschema.Draft7Validator(pack.product_schema).iter_errors(cp.domain_attributes))
        if errors:
            logger.warning("shopify_webhook_schema_error", platform_id=cp.platform_id, error=errors[0].message)

        product = Product(
            tenant_id=tenant_id,
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
        embed_product.delay(str(tenant_id), str(saved.id))

    await db.commit()
    return {"status": "ok"}


@router.post("/orders")
async def shopify_order_webhook(
    request: Request,
    x_eshopeo_tenant_id: str | None = Header(default=None),
    x_shopify_hmac_sha256: str | None = Header(default=None),
    db: AsyncSession = Depends(get_db),
):
    """POST /v1/webhooks/shopify/orders."""
    if x_eshopeo_tenant_id is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Missing tenant ID")
    if x_shopify_hmac_sha256 is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Missing HMAC header")

    try:
        tenant_id = UUID(x_eshopeo_tenant_id)
    except ValueError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid tenant ID")

    tenant = await get_tenant_by_id(db, tenant_id)
    if tenant is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Unknown tenant")

    settings = get_settings()
    creds = decrypt_credentials(tenant.credentials_enc, settings.credential_encryption_key.get_secret_value())
    webhook_secret = creds.get("webhook_secret", "")

    body = await request.body()
    if not verify_shopify_webhook(body, x_shopify_hmac_sha256, webhook_secret):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid webhook signature")

    try:
        payload = json.loads(body)
    except json.JSONDecodeError:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid JSON payload")

    co = translate_shopify_order(payload, tenant_id)

    # Resolve customer_id if a customer_platform_id is present
    customer_id = None
    if co.customer_platform_id:
        customer_id = await get_customer_id_by_platform_id(db, tenant_id, co.customer_platform_id)

    order = Order(
        tenant_id=tenant_id,
        platform_id=co.platform_id,
        customer_id=customer_id,
        total_minor=co.total_minor,
        currency=co.currency,
        status=co.status,
        line_items=co.line_items,
        placed_at=co.placed_at,
    )
    await upsert_order(db, order)

    await db.commit()
    return {"status": "ok"}
