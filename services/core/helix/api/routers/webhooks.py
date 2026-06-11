import base64
import hashlib
import hmac
import json
from datetime import datetime
from typing import Any
from uuid import UUID

import structlog
from fastapi import APIRouter, Header, HTTPException, Request, status, Depends
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.auth.crypto import decrypt_credentials
from helix.api.deps import get_db
from helix.config import get_settings
from helix.db.crud.orders import upsert_order
from helix.db.crud.products import delete_product, upsert_product
from helix.db.crud.tenants import get_tenant_by_id
from helix.db.models import Order, Product, Tenant
from helix.workers.tasks.embedding import embed_product

logger = structlog.get_logger(__name__)
router = APIRouter(prefix="/v1/webhooks", tags=["webhooks"])


def _verify_wc_signature(body: bytes, signature: str, secret: str) -> bool:
    expected = base64.b64encode(
        hmac.new(secret.encode(), body, hashlib.sha256).digest()
    ).decode()
    return hmac.compare_digest(expected, signature)


@router.post("/products")
async def product_webhook(
    request: Request,
    x_helix_tenant_id: str = Header(...),
    x_wc_webhook_signature: str = Header(...),
    db: AsyncSession = Depends(get_db),
) -> dict[str, str]:
    body = await request.body()

    try:
        tenant_id = UUID(x_helix_tenant_id)
    except ValueError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid tenant ID")

    tenant = await get_tenant_by_id(db, tenant_id)
    if tenant is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Unknown tenant")

    settings = get_settings()
    creds = decrypt_credentials(tenant.credentials_enc, settings.credential_encryption_key.get_secret_value())
    webhook_secret = creds.get("webhook_secret", "")

    if not _verify_wc_signature(body, x_wc_webhook_signature, webhook_secret):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid signature")

    payload: dict[str, Any] = json.loads(body)

    if payload.get("deleted"):
        await delete_product(db, tenant.id, str(payload["id"]))
        await db.commit()
        return {"status": "deleted"}

    product = Product(
        tenant_id=tenant.id,
        platform_id=str(payload["id"]),
        title=payload.get("name", ""),
        description_html=payload.get("description") or None,
        price_minor=int(round(float(payload.get("price", "0")) * 100)),
        currency="ZAR",
        images=[img["src"] for img in payload.get("images", []) if "src" in img],
        categories=[c["name"] for c in payload.get("categories", [])],
        in_stock=payload.get("stock_status") == "instock",
        domain_attributes=_extract_domain_attrs(payload),
    )
    saved = await upsert_product(db, product)
    embed_product.delay(str(tenant.id), str(saved.id))
    await db.commit()
    return {"status": "ok"}


def _extract_domain_attrs(payload: dict[str, Any]) -> dict[str, Any]:
    attrs: dict[str, Any] = {}
    for attr in payload.get("attributes", []):
        slug = attr.get("slug", "").replace("pa_", "").replace("-", "_")
        options = attr.get("options", [])
        if slug and options:
            attrs[slug] = options if len(options) > 1 else options[0]
    return attrs


def _translate_wc_order(payload: dict[str, Any], tenant_id: UUID) -> Order:
    placed_raw = payload.get("date_created", "")
    try:
        placed_at = datetime.fromisoformat(placed_raw)
    except (ValueError, TypeError):
        from datetime import timezone
        placed_at = datetime.now(timezone.utc)

    return Order(
        tenant_id=tenant_id,
        platform_id=str(payload["id"]),
        customer_id=None,
        total_minor=int(round(float(payload.get("total", "0")) * 100)),
        currency=payload.get("currency", "ZAR"),
        status=payload.get("status", "unknown"),
        line_items=payload.get("line_items", []),
        placed_at=placed_at,
    )


@router.post("/orders")
async def order_webhook(
    request: Request,
    x_helix_tenant_id: str = Header(...),
    x_wc_webhook_signature: str = Header(...),
    db: AsyncSession = Depends(get_db),
) -> dict[str, str]:
    body = await request.body()

    try:
        tenant_id = UUID(x_helix_tenant_id)
    except ValueError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid tenant ID")

    tenant = await get_tenant_by_id(db, tenant_id)
    if tenant is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Unknown tenant")

    settings = get_settings()
    creds = decrypt_credentials(tenant.credentials_enc, settings.credential_encryption_key.get_secret_value())
    webhook_secret = creds.get("webhook_secret", "")

    if not _verify_wc_signature(body, x_wc_webhook_signature, webhook_secret):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid signature")

    payload: dict[str, Any] = json.loads(body)
    order = _translate_wc_order(payload, tenant_id)
    await upsert_order(db, order)
    await db.commit()
    return {"status": "ok"}
