import hashlib
import hmac
import base64
import json
import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from httpx import AsyncClient, ASGITransport

from helix.api.app import create_app
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def make_wc_product_payload(platform_id: str = "99") -> dict:
    return {
        "id": int(platform_id),
        "name": "Toner",
        "price": "199.00",
        "description": "",
        "images": [],
        "categories": [],
        "attributes": [],
        "stock_status": "instock",
    }


def wc_signature(body: bytes, secret: str) -> str:
    mac = hmac.new(secret.encode(), body, hashlib.sha256).digest()
    return base64.b64encode(mac).decode()


@pytest.fixture
def tenant():
    from helix.api.auth.crypto import encrypt_credentials
    from tests.conftest import make_test_settings
    settings = make_test_settings()
    t = MagicMock()
    t.id = uuid4()
    t.public_key = uuid4()
    t.platform = "woocommerce"
    t.credentials_enc = encrypt_credentials(
        {"webhook_secret": "test-secret"},
        settings.credential_encryption_key.get_secret_value(),
    )
    return t


@pytest.fixture
def app():
    return create_app(make_test_settings())


@pytest.fixture
async def client(app):
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        yield c


@pytest.mark.asyncio
async def test_webhook_bad_signature_returns_401(client, tenant):
    body = json.dumps(make_wc_product_payload()).encode()
    with patch("helix.api.routers.webhooks.get_tenant_by_id", new_callable=AsyncMock, return_value=tenant):
        resp = await client.post(
            "/v1/webhooks/products",
            content=body,
            headers={
                "X-Helix-Tenant-Id": str(tenant.id),
                "X-WC-Webhook-Signature": "badsig",
                "Content-Type": "application/json",
            },
        )
    assert resp.status_code == 401


@pytest.mark.asyncio
async def test_webhook_valid_signature_accepted(client, tenant):
    payload = make_wc_product_payload()
    body = json.dumps(payload).encode()
    sig = wc_signature(body, "test-secret")

    with patch("helix.api.routers.webhooks.get_tenant_by_id", new_callable=AsyncMock, return_value=tenant), \
         patch("helix.api.routers.webhooks.upsert_product", new_callable=AsyncMock), \
         patch("helix.api.routers.webhooks.embed_product"), \
         patch("helix.api.routers.webhooks.get_db"):

        resp = await client.post(
            "/v1/webhooks/products",
            content=body,
            headers={
                "X-Helix-Tenant-Id": str(tenant.id),
                "X-WC-Webhook-Signature": sig,
                "Content-Type": "application/json",
            },
        )
    assert resp.status_code == 200
