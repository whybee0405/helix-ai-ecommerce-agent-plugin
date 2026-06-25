"""
Paddle Billing API client + webhook signature verification.

Paddle uses HMAC-SHA256 with the webhook secret to sign event payloads.
Verification follows Paddle's documented ts:signature format.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import time

import httpx
import structlog

logger = structlog.get_logger(__name__)

_PADDLE_API_BASE = "https://api.paddle.com"
_SANDBOX_API_BASE = "https://sandbox-api.paddle.com"


class PaddleClient:
    def __init__(self, api_key: str, sandbox: bool = False) -> None:
        self._api_key = api_key
        self._base = _SANDBOX_API_BASE if sandbox else _PADDLE_API_BASE

    def _headers(self) -> dict:
        return {
            "Authorization": f"Bearer {self._api_key}",
            "Content-Type": "application/json",
        }

    async def create_checkout(
        self,
        price_id: str,
        customer_email: str,
        tenant_id: str,
        success_url: str,
        cancel_url: str,
    ) -> dict:
        """Create a Paddle checkout session and return the checkout URL."""
        payload = {
            "items": [{"price_id": price_id, "quantity": 1}],
            "customer": {"email": customer_email},
            "custom_data": {"tenant_id": tenant_id},
            "success_url": success_url,
            "cancel_url": cancel_url,
        }
        async with httpx.AsyncClient(timeout=15) as http:
            resp = await http.post(
                f"{self._base}/transactions",
                json=payload,
                headers=self._headers(),
            )
            resp.raise_for_status()
            data = resp.json()["data"]
            checkout_url = data.get("checkout", {}).get("url") or data.get("url", "")
            return {"checkout_url": checkout_url, "transaction_id": data.get("id")}

    async def get_customer_portal_url(self, customer_id: str) -> str:
        """Generate a Paddle customer portal session URL."""
        async with httpx.AsyncClient(timeout=15) as http:
            resp = await http.post(
                f"{self._base}/customers/{customer_id}/auth-token",
                headers=self._headers(),
            )
            resp.raise_for_status()
            token = resp.json()["data"]["customer_auth_token"]
        base = "https://sandbox-" if "sandbox" in self._base else ""
        return f"https://{base}customer.paddle.com?token={token}"

    async def cancel_subscription(self, subscription_id: str) -> None:
        async with httpx.AsyncClient(timeout=15) as http:
            resp = await http.post(
                f"{self._base}/subscriptions/{subscription_id}/cancel",
                json={"effective_from": "next_billing_period"},
                headers=self._headers(),
            )
            resp.raise_for_status()


def verify_webhook(raw_body: bytes, signature_header: str, secret: str) -> bool:
    """
    Verify a Paddle webhook signature.
    Paddle sends: Paddle-Signature: ts=<timestamp>;h1=<hmac_hex>
    """
    try:
        parts = dict(p.split("=", 1) for p in signature_header.split(";"))
        ts = parts.get("ts", "")
        h1 = parts.get("h1", "")

        # Reject stale webhooks older than 5 minutes.
        if abs(time.time() - int(ts)) > 300:
            logger.warning("paddle_webhook_stale", ts=ts)
            return False

        signed_payload = f"{ts}:{raw_body.decode()}"
        expected = hmac.new(
            secret.encode(), signed_payload.encode(), hashlib.sha256
        ).hexdigest()
        return hmac.compare_digest(expected, h1)
    except Exception as exc:
        logger.warning("paddle_webhook_verify_error", error=str(exc))
        return False


def parse_event(raw_body: bytes) -> dict:
    return json.loads(raw_body)
