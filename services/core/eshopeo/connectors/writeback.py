import base64
import json

import httpx
import structlog
from cryptography.fernet import Fernet

from eshopeo.config import Settings
from eshopeo.db.models import Tenant

logger = structlog.get_logger(__name__)

_SUPPORTED_FIELDS = {"description_html"}


async def write_back_to_platform(
    tenant: Tenant,
    platform_id: str,
    field: str,
    text: str,
    settings: Settings,
) -> bool:
    """Push approved content to the merchant's live store. Returns True on success."""
    if field not in _SUPPORTED_FIELDS:
        return False
    try:
        f = Fernet(settings.credential_encryption_key.get_secret_value().encode())
        creds = json.loads(f.decrypt(tenant.credentials_enc))

        if tenant.platform == "woocommerce":
            await _write_woocommerce(tenant.store_url, platform_id, field, text, creds)
        elif tenant.platform == "shopify":
            await _write_shopify(tenant.store_url, platform_id, field, text, creds)
        else:
            logger.warning("write_back_unsupported_platform", platform=tenant.platform)
            return False

        logger.info(
            "write_back_success",
            platform=tenant.platform,
            product_platform_id=platform_id,
            field=field,
        )
        return True

    except Exception as exc:
        logger.warning(
            "write_back_failed",
            platform=getattr(tenant, "platform", "unknown"),
            product_platform_id=platform_id,
            field=field,
            error=str(exc),
        )
        return False


async def _write_woocommerce(
    store_url: str, platform_id: str, field: str, text: str, creds: dict
) -> None:
    token = base64.b64encode(
        f"{creds['consumer_key']}:{creds['consumer_secret']}".encode()
    ).decode()
    payload = {"description": text} if field == "description_html" else {}
    async with httpx.AsyncClient(timeout=10.0) as client:
        r = await client.put(
            f"{store_url.rstrip('/')}/wp-json/wc/v3/products/{platform_id}",
            headers={"Authorization": f"Basic {token}"},
            json=payload,
        )
        r.raise_for_status()


async def _write_shopify(
    store_url: str, platform_id: str, field: str, text: str, creds: dict
) -> None:
    payload = {"product": {"body_html": text}} if field == "description_html" else {}
    async with httpx.AsyncClient(timeout=10.0) as client:
        r = await client.put(
            f"{store_url.rstrip('/')}/admin/api/2024-01/products/{platform_id}.json",
            headers={"X-Shopify-Access-Token": creds["access_token"]},
            json=payload,
        )
        r.raise_for_status()
