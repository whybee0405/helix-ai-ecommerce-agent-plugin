import base64
import hashlib
import hmac
import json
import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from httpx import AsyncClient, ASGITransport

from helix.api.app import create_app
from helix.connectors.shopify import verify_shopify_webhook, translate_shopify_product
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def shopify_sig(body: bytes, secret: str) -> str:
    return base64.b64encode(
        hmac.new(secret.encode(), body, hashlib.sha256).digest()
    ).decode()


def make_shopify_product(product_id: int = 99) -> dict:
    return {
        "id": product_id,
        "title": "Glow Serum",
        "body_html": "<p>Brightening</p>",
        "status": "active",
        "images": [{"src": "https://cdn.example.com/img.jpg"}],
        "variants": [{"price": "349.00", "inventory_quantity": 10}],
        "collections": [],
    }


def test_verify_shopify_webhook_valid():
    body = b'{"id": 1}'
    secret = "test-secret"
    sig = shopify_sig(body, secret)
    assert verify_shopify_webhook(body, sig, secret) is True


def test_verify_shopify_webhook_invalid():
    assert verify_shopify_webhook(b'{"id": 1}', "badsig==", "secret") is False


def test_translate_shopify_product():
    tenant_id = uuid4()
    payload = make_shopify_product(42)
    cp = translate_shopify_product(payload, tenant_id)
    assert cp.platform == "shopify"
    assert cp.platform_id == "42"
    assert cp.title == "Glow Serum"
    assert cp.price_minor == 34900
    assert cp.in_stock is True
    assert cp.deleted is False


@pytest.fixture
def tenant():
    from helix.api.auth.crypto import encrypt_credentials
    settings = make_test_settings()
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    t.public_key = uuid4()
    t.credentials_enc = encrypt_credentials(
        {"webhook_secret": "shop-secret"},
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


async def test_shopify_webhook_bad_sig_returns_401(client, tenant):
    body = json.dumps(make_shopify_product()).encode()
    with patch("helix.api.routers.shopify_webhooks.get_tenant_by_id", new_callable=AsyncMock, return_value=tenant):
        resp = await client.post(
            "/v1/webhooks/shopify/products",
            content=body,
            headers={
                "X-Helix-Tenant-Id": str(tenant.id),
                "X-Shopify-Hmac-Sha256": "invalidsig==",
                "Content-Type": "application/json",
            },
        )
    assert resp.status_code == 401


async def test_shopify_webhook_valid_accepted(client, tenant):
    payload = make_shopify_product()
    body = json.dumps(payload).encode()
    sig = shopify_sig(body, "shop-secret")

    with patch("helix.api.routers.shopify_webhooks.get_tenant_by_id", new_callable=AsyncMock, return_value=tenant), \
         patch("helix.api.routers.shopify_webhooks.upsert_product", new_callable=AsyncMock) as mock_upsert, \
         patch("helix.api.routers.shopify_webhooks.embed_product"), \
         patch("helix.api.routers.shopify_webhooks.get_pack_for_tenant") as mock_get_pack, \
         patch("helix.api.routers.shopify_webhooks.get_db"):
        mock_get_pack.return_value = MagicMock(product_schema={"type": "object", "properties": {}, "required": []})
        mock_upsert.return_value = MagicMock(id=uuid4())

        resp = await client.post(
            "/v1/webhooks/shopify/products",
            content=body,
            headers={
                "X-Helix-Tenant-Id": str(tenant.id),
                "X-Shopify-Hmac-Sha256": sig,
                "Content-Type": "application/json",
            },
        )
    assert resp.status_code == 200
