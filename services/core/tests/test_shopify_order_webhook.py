import base64
import hashlib
import hmac
import json
import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from datetime import datetime, timezone
from httpx import AsyncClient, ASGITransport

from helix.api.app import create_app
from helix.connectors.shopify import verify_shopify_webhook, translate_shopify_order
from helix.connectors.models import CanonicalOrder
from helix.db.models import Tenant, Order
from tests.conftest import make_test_settings


def shopify_sig(body: bytes, secret: str) -> str:
    return base64.b64encode(
        hmac.new(secret.encode(), body, hashlib.sha256).digest()
    ).decode()


def make_shopify_order(order_id: int = 12345, customer_id: int = 67890) -> dict:
    return {
        "id": order_id,
        "customer": {"id": customer_id},
        "total_price": "250.00",
        "currency": "USD",
        "financial_status": "paid",
        "line_items": [
            {"id": 1, "title": "Product 1", "quantity": 1, "price": "100.00"},
            {"id": 2, "title": "Product 2", "quantity": 1, "price": "150.00"},
        ],
        "created_at": "2026-06-11T10:00:00+00:00",
    }


def test_translate_shopify_order():
    """Unit test: translate_shopify_order populates fields correctly"""
    tenant_id = uuid4()
    payload = make_shopify_order(12345, 67890)

    co = translate_shopify_order(payload, tenant_id)

    assert co.platform == "shopify"
    assert co.platform_id == "12345"
    assert co.total_minor == 25000  # 250.00 * 100
    assert co.currency == "USD"
    assert co.status == "paid"  # maps from financial_status
    assert co.customer_platform_id == "67890"
    assert co.tenant_id == tenant_id
    assert isinstance(co.placed_at, datetime)


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


async def test_shopify_order_webhook_rejects_bad_signature(client, tenant):
    """Bad HMAC → 401"""
    body = json.dumps(make_shopify_order()).encode()
    with patch("helix.api.routers.shopify_webhooks.get_tenant_by_id", new_callable=AsyncMock, return_value=tenant):
        resp = await client.post(
            "/v1/webhooks/shopify/orders",
            content=body,
            headers={
                "X-Helix-Tenant-Id": str(tenant.id),
                "X-Shopify-Hmac-Sha256": "invalidsig==",
                "Content-Type": "application/json",
            },
        )
    assert resp.status_code == 401


async def test_shopify_order_webhook_rejects_unknown_tenant(client):
    """Unknown tenant → 401"""
    payload = make_shopify_order()
    body = json.dumps(payload).encode()

    with patch("helix.api.routers.shopify_webhooks.get_tenant_by_id", new_callable=AsyncMock, return_value=None):
        resp = await client.post(
            "/v1/webhooks/shopify/orders",
            content=body,
            headers={
                "X-Helix-Tenant-Id": str(uuid4()),
                "X-Shopify-Hmac-Sha256": "somesig==",
                "Content-Type": "application/json",
            },
        )
    assert resp.status_code == 401


async def test_shopify_order_webhook_accepts_valid_payload(client, tenant):
    """Valid HMAC → 200, {"status": "ok"}"""
    payload = make_shopify_order()
    body = json.dumps(payload).encode()
    sig = shopify_sig(body, "shop-secret")

    mock_order = MagicMock(spec=Order)
    mock_order.id = uuid4()
    mock_order.tenant_id = tenant.id
    mock_order.platform_id = "12345"
    mock_order.customer_id = None
    mock_order.total_minor = 25000
    mock_order.currency = "USD"
    mock_order.status = "paid"
    mock_order.line_items = payload["line_items"]

    with patch("helix.api.routers.shopify_webhooks.get_tenant_by_id", new_callable=AsyncMock, return_value=tenant), \
         patch("helix.api.routers.shopify_webhooks.upsert_order", new_callable=AsyncMock, return_value=mock_order) as mock_upsert, \
         patch("helix.api.routers.shopify_webhooks.get_customer_id_by_platform_id", new_callable=AsyncMock, return_value=None), \
         patch("helix.api.routers.shopify_webhooks.get_db"):

        resp = await client.post(
            "/v1/webhooks/shopify/orders",
            content=body,
            headers={
                "X-Helix-Tenant-Id": str(tenant.id),
                "X-Shopify-Hmac-Sha256": sig,
                "Content-Type": "application/json",
            },
        )

    assert resp.status_code == 200
    assert resp.json() == {"status": "ok"}
    # Verify upsert_order was called
    assert mock_upsert.called
