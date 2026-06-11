import base64
import hashlib
import hmac
from datetime import datetime, timezone
from uuid import UUID

from helix.connectors.models import CanonicalOrder, CanonicalProduct


def verify_shopify_webhook(body: bytes, hmac_header: str, secret: str) -> bool:
    """Verify Shopify webhook HMAC-SHA256 signature (base64-encoded)."""
    expected = base64.b64encode(
        hmac.new(secret.encode(), body, hashlib.sha256).digest()
    ).decode()
    return hmac.compare_digest(expected, hmac_header)


def translate_shopify_product(payload: dict, tenant_id: UUID) -> CanonicalProduct:
    """Map Shopify product webhook payload to CanonicalProduct."""
    variant = payload.get("variants", [{}])[0]
    price_str = variant.get("price", "0") or "0"
    try:
        price_minor = int(float(price_str) * 100)
    except (ValueError, TypeError):
        price_minor = 0

    return CanonicalProduct(
        tenant_id=tenant_id,
        platform="shopify",
        platform_id=str(payload.get("id", "")),
        title=payload.get("title", ""),
        description_html=payload.get("body_html"),
        price_minor=price_minor,
        currency="USD",
        images=[img.get("src", "") for img in payload.get("images", []) if img.get("src")],
        categories=[c.get("title", "") for c in payload.get("collections", [])],
        in_stock=(variant.get("inventory_quantity", 1) or 1) > 0,
        domain_attributes={},
        deleted=payload.get("status") == "archived",
    )


def translate_shopify_order(payload: dict, tenant_id: UUID) -> CanonicalOrder:
    """Map Shopify order webhook payload to CanonicalOrder."""
    total_price_str = payload.get("total_price", "0") or "0"
    try:
        total_minor = int(float(total_price_str) * 100)
    except (ValueError, TypeError):
        total_minor = 0

    created_at_str = payload.get("created_at")
    if created_at_str:
        try:
            placed_at = datetime.fromisoformat(created_at_str.replace("Z", "+00:00"))
        except (ValueError, TypeError):
            placed_at = datetime.now(timezone.utc)
    else:
        placed_at = datetime.now(timezone.utc)

    customer = payload.get("customer", {})
    customer_platform_id = None
    if customer and customer.get("id"):
        customer_platform_id = str(customer.get("id"))

    return CanonicalOrder(
        tenant_id=tenant_id,
        platform="shopify",
        platform_id=str(payload.get("id", "")),
        customer_platform_id=customer_platform_id,
        total_minor=total_minor,
        currency=payload.get("currency", "USD"),
        status=payload.get("financial_status", ""),
        line_items=payload.get("line_items", []),
        placed_at=placed_at,
    )
