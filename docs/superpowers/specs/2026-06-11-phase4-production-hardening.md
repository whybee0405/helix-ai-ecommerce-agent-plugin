# Phase 4 — Production Hardening Design Spec

**Date:** 2026-06-11  
**Status:** Approved  
**Scope:** CORS, request correlation, orders data loop, monthly usage quota  
**Definition of done:** Widget JS can make cross-origin requests; every response carries a correlation ID; operators can sync orders; the platform enforces a monthly query cap per tenant.

---

## 1. Gap analysis from Phase 3

| Gap | Impact |
|-----|--------|
| No CORS headers on widget/API endpoints | Browser blocks cross-origin widget calls — widget is unshippable |
| No request correlation ID | Production debugging is impossible without log correlation |
| No orders sync | Data loop incomplete — products + customers exist, orders missing |
| No monthly usage cap | Operators can't limit per-tenant spend; abuse risk |

---

## 2. CORS middleware (P4-1)

Widget JS makes `fetch()` calls from the merchant's storefront domain to the Helix API. Without CORS, every browser blocks those requests.

### Settings change

Add to `helix/config.py` → `Settings`:
```python
cors_allowed_origins: list[str] = ["*"]
```

Operators restrict this in production by setting `CORS_ALLOWED_ORIGINS=https://mystore.com` in env.

### Middleware registration

In `helix/api/app.py`, add **before** RateLimitMiddleware (outermost layer processes first):
```python
from starlette.middleware.cors import CORSMiddleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=s.cors_allowed_origins,
    allow_methods=["GET", "POST", "PATCH"],
    allow_headers=["Authorization", "Content-Type", "X-Helix-Tenant-Key", "X-Helix-Provision-Key"],
    expose_headers=["X-Request-Id"],
)
```

---

## 3. X-Request-Id middleware (P4-2)

Every request gets a unique ID for log correlation. If the caller supplies `X-Request-Id`, echo it back; otherwise generate a new UUID4.

### Implementation: `helix/api/middleware/request_id.py`

```python
import uuid
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request

class RequestIdMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        request_id = request.headers.get("X-Request-Id") or str(uuid.uuid4())
        response = await call_next(request)
        response.headers["X-Request-Id"] = request_id
        return response
```

Register in `app.py` — inner layer (after CORS, before rate limit).

No structlog binding needed at middleware level (structlog context doesn't flow across async boundaries cleanly without extra setup). The `X-Request-Id` header in the response is sufficient for log correlation by operators.

---

## 4. Orders sync endpoint (P4-3)

Closes the data loop. `CanonicalOrder` is already defined in `helix/connectors/models.py`. The `Order` model exists in `helix/db/models.py`.

### New: `helix/db/crud/orders.py`

```python
async def upsert_order(session, order: Order) -> Order:
    # INSERT ... ON CONFLICT ON CONSTRAINT uq_order_tenant_platform DO UPDATE
```

The `uq_order_tenant_platform` unique constraint is on `(tenant_id, platform_id)` — defined in `Order.__table_args__`.

Also needs `customer_id` lookup: when `CanonicalOrder.customer_platform_id` is provided, look up the Customer row and populate `Order.customer_id`.

### Endpoint: extend `helix/api/routers/sync.py`

`POST /v1/sync/orders` — same auth and response shape as `POST /v1/sync/products`:
- Accept `list[CanonicalOrder]`
- For each: look up customer by platform_id if provided, upsert Order row
- Return `OrderSyncResponse(synced, failed, errors)`

---

## 5. Monthly usage quota (P4-4)

Prevent tenants from exhausting LLM budget by enforcing a monthly query cap.

### Settings change

Add to `Settings`:
```python
default_monthly_query_limit: int = 10_000
```

### Middleware: `helix/api/middleware/quota.py`

Tracks widget query volume in Redis. Key: `quota:{tenant_id}:{YYYY-MM}`, TTL 32 days (covers end-of-month).

Logic (on `/v1/widget/chat` and `/v1/widget/routine` only):
1. Extract `tenant_id` from JWT (same as rate_limit — unverified claims, for keying only)
2. If no tenant_id, pass through (auth will reject it anyway)
3. INCR the monthly counter; set 32-day TTL on first hit
4. If count > `settings.default_monthly_query_limit`: return `429` with `X-Quota-Exceeded: monthly`
5. Fails open on Redis error

Quota check happens BEFORE rate-limit check (quota is the outer gate — if monthly limit hit, no point rate-limiting).

Register in `app.py` before `RateLimitMiddleware` but after CORS and RequestId.

---

## 6. WooCommerce orders webhook (P4-5)

Extends the existing WooCommerce webhook router to handle real-time order events.

`POST /v1/webhooks/woocommerce/orders`
- Headers: `X-Helix-Tenant-Id`, `X-WC-Webhook-Signature`
- Verify HMAC-SHA256 hex signature (same as products webhook)
- Parse order payload into `CanonicalOrder` using a `translate_woocommerce_order()` function
- Upsert via `upsert_order()`

New function in `helix/connectors/woocommerce.py` (if it exists) or inline in the router:

```python
def translate_woocommerce_order(payload: dict, tenant_id: UUID) -> CanonicalOrder:
    # platform="woocommerce"
    # customer_platform_id = str(payload["customer_id"]) if payload.get("customer_id") else None
    # total_minor = int(float(payload.get("total", "0")) * 100)
    # placed_at = datetime.fromisoformat(payload["date_created"])
```

---

## 7. File map

**New files:**
- `services/core/helix/api/middleware/request_id.py`
- `services/core/helix/api/middleware/quota.py`
- `services/core/helix/db/crud/orders.py`

**Modified files:**
- `services/core/helix/config.py` — add `cors_allowed_origins`, `default_monthly_query_limit`
- `services/core/helix/api/app.py` — register CORSMiddleware, RequestIdMiddleware, QuotaMiddleware
- `services/core/helix/api/routers/sync.py` — add `POST /v1/sync/orders`
- `services/core/helix/api/routers/webhooks.py` — add `POST /v1/webhooks/woocommerce/orders`

**New tests:**
- `services/core/tests/test_cors.py` (3 tests)
- `services/core/tests/test_request_id.py` (3 tests)
- `services/core/tests/test_orders_sync.py` (4 tests)
- `services/core/tests/test_quota.py` (4 tests)
- `services/core/tests/test_woocommerce_order_webhook.py` (3 tests)

---

## 8. Security constraints

- CORS: `expose_headers` exposes only `X-Request-Id` — no internal headers leaked
- `X-Request-Id` echoed from caller is not sanitized (UUIDs only in practice; log injection risk negligible)
- Quota key uses unverified JWT claims (same pattern as rate limiter) — keying only, not authorization
- Order upsert: tenant_id comes from the authenticated tenant object, not from the payload body — no tenant spoofing
- Webhook HMAC verification unchanged — WooCommerce order webhook uses same verification path

---

## 9. Middleware stack order (outermost → innermost)

```
CORS → RequestId → Quota → RateLimit → FastAPI routing
```

CORS must be outermost so preflight OPTIONS responses are handled before any business logic.
