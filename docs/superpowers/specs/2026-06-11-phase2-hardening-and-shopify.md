# Phase 2 — Hardening & Shopify Design Spec

**Date:** 2026-06-11  
**Status:** Approved  
**Scope:** Complete stub layers, Redis rate limiting, usage analytics API, Shopify connector  
**Definition of done:** All layer stubs are replaced with working implementations; widget endpoints are rate-limited per-tenant; operators can query usage via API; a Shopify store can connect, sync products, and receive webhooks.

---

## 1. Deferred items from Phase 1

Two layers remain stubs — they return `LayerResult(answered=False)` unconditionally:

- **`TemplateLayer.query()`** — should match FAQ patterns from pack copy templates (case-insensitive substring)
- **`VectorSearchLayer.query()`** — currently bypassed (the router calls `vector_search_products()` directly); this layer's `query()` needs to actually run a DB query for use inside `route_query()`

These are zero-cost layers; completing them reduces LLM call volume.

---

## 2. TemplateLayer implementation

`TemplateLayer.query(query_text, templates: dict[str, str]) -> LayerResult`

Templates come from the pack's `copy/en.json`. The structure is a flat dict of key → answer string. Matching is case-insensitive substring of the **key** against the query text.

```python
# Example pack copy/en.json
{
  "return policy": "We accept returns within 30 days.",
  "shipping": "Free shipping on orders over R500.",
  "skin type quiz": "Take our quiz at /quiz to find your skin type."
}
```

If any key appears as a substring of the lowercased query, return `LayerResult(answered=True, response=<value>, confidence=1.0)`. First match wins.

---

## 3. VectorSearchLayer.query() implementation

`VectorSearchLayer.query(session, tenant_id, query_text, settings, top_k) -> LayerResult`

Signature must change to accept the DB session and settings (needed for embedding). The layer:
1. Calls `embed_query(query_text, settings)`
2. Calls `vector_search_products(session, tenant_id, vector, limit=top_k)`
3. If results found: returns `LayerResult(answered=True, response=results, confidence=top_score)`
4. If no results: returns `LayerResult(answered=False)`

**Note:** `route_query()` in the gateway will need to be updated to pass session + settings when calling `VectorSearchLayer.query()`.

---

## 4. Redis rate limiting

Protect widget endpoints (`/v1/widget/chat`, `/v1/widget/routine`) from abuse.

**Strategy:** Sliding window counter in Redis.
- Key: `ratelimit:{tenant_id}:{endpoint}` — scoped per tenant per endpoint
- Window: 60 seconds
- Limit: 30 requests per window per tenant (configurable via `Settings`)
- Response on limit exceeded: `429 Too Many Requests` with `Retry-After` header

Implementation: `eshopeo/api/middleware/rate_limit.py`

```python
class RateLimitMiddleware:
    """Sliding window rate limiter for widget endpoints."""
    WIDGET_PATHS = {"/v1/widget/chat", "/v1/widget/routine"}
    WINDOW_SECONDS = 60
    
    async def __call__(self, request, call_next):
        if request.url.path not in self.WIDGET_PATHS:
            return await call_next(request)
        # extract tenant_id from JWT in Authorization header
        # increment Redis counter; reject with 429 if over limit
        ...
```

Add `Settings.widget_rate_limit: int = 30` field.

Register middleware in `create_app()` using `app.add_middleware(RateLimitMiddleware, settings=s)`.

---

## 5. Usage analytics endpoint

`GET /v1/analytics/usage` — auth: `X-eShopeo-Tenant-Key`

Query params:
- `start_date: date` (optional, default: 30 days ago)
- `end_date: date` (optional, default: today)

Response:
```json
{
  "tenant_id": "...",
  "period": {"start": "2026-05-12", "end": "2026-06-11"},
  "total_queries": 1234,
  "llm_calls": 123,
  "cached_calls": 1111,
  "total_cost_usd": 0.45,
  "by_model": [
    {"model": "claude-haiku-4-5", "calls": 100, "cost_usd": 0.12},
    {"model": "claude-sonnet-4-6", "calls": 23, "cost_usd": 0.33}
  ]
}
```

Implementation: `eshopeo/db/crud/usage.py` (extend), `eshopeo/api/routers/analytics.py` (new).

---

## 6. Shopify connector

### 6a. Python side — connector contract

`eshopeo/connectors/shopify.py`:
- `ShopifyWebhookVerifier.verify(body: bytes, hmac_header: str, secret: str) -> bool` — HMAC-SHA256 of base64-encoded body digest (Shopify's scheme)
- `translate_product(payload: dict) -> CanonicalProduct` — maps Shopify product payload to canonical model

New router `eshopeo/api/routers/shopify_webhooks.py`:
- `POST /v1/webhooks/shopify/products` — same pattern as WooCommerce webhook router (verify, parse, upsert/delete)

### 6b. PHP Shopify App (connectors/shopify/)

Minimal Shopify app (no Composer) following the same pattern as the WooCommerce plugin:
- `eshopeo-shopify.php` — main plugin file (admin + API client)
- `includes/class-eshopeo-shopify-api-client.php` — `provision()`, `sync_products()`  
- `includes/class-eshopeo-shopify-sync.php` — `run_full_sync()`, `translate_product()`
- `includes/class-eshopeo-shopify-webhooks.php` — register/remove product webhooks using Shopify Admin API

Auth: Shopify uses `X-Shopify-Hmac-Sha256` header for webhooks (base64-encoded SHA-256 of the raw body using the secret).

---

## 7. File map

**New files:**
- `services/core/eshopeo/api/middleware/__init__.py`
- `services/core/eshopeo/api/middleware/rate_limit.py`
- `services/core/eshopeo/api/routers/analytics.py`
- `services/core/eshopeo/connectors/shopify.py`
- `services/core/eshopeo/api/routers/shopify_webhooks.py`
- `connectors/shopify/eshopeo-shopify.php`
- `connectors/shopify/includes/class-eshopeo-shopify-api-client.php`
- `connectors/shopify/includes/class-eshopeo-shopify-sync.php`
- `connectors/shopify/includes/class-eshopeo-shopify-webhooks.php`

**Modified files:**
- `services/core/eshopeo/llm/layers.py` — implement `TemplateLayer.query()` and `VectorSearchLayer.query()`
- `services/core/eshopeo/llm/gateway.py` — update `route_query()` to pass session+settings to `VectorSearchLayer`
- `services/core/eshopeo/db/crud/usage.py` — add `get_usage_summary()`
- `services/core/eshopeo/config.py` — add `widget_rate_limit: int = 30`
- `services/core/eshopeo/api/app.py` — register analytics router, shopify webhook router, rate limit middleware

**New tests:**
- `services/core/tests/test_template_layer.py`
- `services/core/tests/test_vector_layer.py`
- `services/core/tests/test_rate_limit.py`
- `services/core/tests/test_analytics_endpoint.py`
- `services/core/tests/test_shopify_webhook.py`

---

## 8. Security constraints (unchanged)

- Rate limiting scoped by `tenant_id` (not IP — widget is embedded, IPs change)
- Shopify webhook verification uses HMAC-SHA256 on the raw request body
- No raw PII stored; `email_hash` only
- Tenant isolation on all analytics queries

---

## Cost impact

- TemplateLayer completing: reduces LLM call share from ~10% to ~5% on FAQ-heavy stores
- Rate limiting: no cost impact, prevents abuse
- Analytics: no new LLM calls
- Shopify: opens second revenue stream (same per-tenant pricing)
