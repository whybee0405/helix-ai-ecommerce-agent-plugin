import base64
import hashlib
import hmac
import json
from datetime import datetime, timezone
from unittest.mock import AsyncMock, patch
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_db
from helix.db.models import Order, Tenant
from tests.conftest import make_test_settings

SECRET = "wc-secret-123"


def _sign(body: bytes) -> str:
    return base64.b64encode(
        hmac.new(SECRET.encode(), body, hashlib.sha256).digest()
    ).decode()


@pytest.fixture
def tenant():
    t = Tenant(name="x", platform="woocommerce", store_url="https://x.com",
               credentials_enc=b"enc")
    t.id = uuid4()
    t.public_key = uuid4()
    return t


WC_ORDER = {
    "id": 999,
    "customer_id": 0,
    "total": "250.00",
    "currency": "ZAR",
    "status": "processing",
    "line_items": [{"product_id": 1, "quantity": 1}],
    "date_created": "2026-06-11T10:00:00",
}


@pytest.fixture
def client(tenant):
    settings = make_test_settings()
    app = create_app(settings)
    mock_db = AsyncMock()
    mock_db.__aenter__ = AsyncMock(return_value=mock_db)
    mock_db.__aexit__ = AsyncMock(return_value=False)
    mock_db.commit = AsyncMock()

    upserted = Order(tenant_id=tenant.id, platform_id="999",
                     total_minor=25000, currency="ZAR", status="processing",
                     line_items=[], placed_at=datetime.now(timezone.utc))
    upserted.id = uuid4()

    app.dependency_overrides[get_db] = lambda: mock_db

    with (
        patch("helix.api.routers.webhooks.get_tenant_by_id", AsyncMock(return_value=tenant)),
        patch("helix.api.routers.webhooks.decrypt_credentials",
              return_value={"webhook_secret": SECRET}),
        patch("helix.api.routers.webhooks.upsert_order", AsyncMock(return_value=upserted)),
    ):
        yield TestClient(app), tenant


def test_wc_order_webhook_accepts_valid_payload(client):
    c, tenant = client
    body = json.dumps(WC_ORDER).encode()
    r = c.post(
        "/v1/webhooks/orders",
        content=body,
        headers={
            "X-Helix-Tenant-Id": str(tenant.id),
            "X-WC-Webhook-Signature": _sign(body),
            "Content-Type": "application/json",
        },
    )
    assert r.status_code == 200
    assert r.json()["status"] == "ok"


def test_wc_order_webhook_rejects_bad_signature(client):
    c, tenant = client
    body = json.dumps(WC_ORDER).encode()
    r = c.post(
        "/v1/webhooks/orders",
        content=body,
        headers={
            "X-Helix-Tenant-Id": str(tenant.id),
            "X-WC-Webhook-Signature": "bad-sig",
            "Content-Type": "application/json",
        },
    )
    assert r.status_code == 401


def test_wc_order_webhook_rejects_unknown_tenant(client):
    c, _ = client
    body = json.dumps(WC_ORDER).encode()
    with patch("helix.api.routers.webhooks.get_tenant_by_id", AsyncMock(return_value=None)):
        r = c.post(
            "/v1/webhooks/orders",
            content=body,
            headers={
                "X-Helix-Tenant-Id": str(uuid4()),
                "X-WC-Webhook-Signature": _sign(body),
                "Content-Type": "application/json",
            },
        )
    assert r.status_code == 401
