# Phase 1 — Intelligence Layer Design Spec

**Date:** 2026-06-11  
**Status:** Approved  
**Scope:** Semantic search, rule engine, AI consultant, routine builder, customer sync, usage metering  
**Definition of done:** A widget session holder can query the AI consultant and receive answers sourced from pgvector search, compatibility rules, templates, and (fallback) Claude. The routine builder produces a step-ordered product list respecting pack rules. All LLM calls are metered to `usage_event`.

---

## 1. New endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `GET` | `/v1/search/products` | `X-Helix-Tenant-Key` | Semantic product search |
| `POST` | `/v1/sync/customers` | `X-Helix-Tenant-Key` | Batch upsert customers |
| `POST` | `/v1/widget/chat` | `Authorization: Bearer <jwt>` | AI consultant query |
| `POST` | `/v1/widget/routine` | `Authorization: Bearer <jwt>` | Personalised routine builder |

---

## 2. Semantic search (`GET /v1/search/products`)

**Query params:** `q` (required), `limit` (default 10, max 50), `in_stock_only` (bool, default false).

**Flow:**
1. Embed `q` via Voyage AI `voyage-3-lite` (same model as product embeddings).
2. Query `product` table filtered by `tenant_id`, ordered by `embedding <=> query_vector` (cosine distance, pgvector operator), LIMIT `limit`.
3. Return ranked list of products with similarity score.

**No LLM call.** This is Layer 1 — zero AI cost.

**Response:**
```json
{
  "results": [
    {
      "id": "...",
      "platform_id": "42",
      "title": "Snail Mucin Essence",
      "price_minor": 34900,
      "currency": "ZAR",
      "in_stock": true,
      "categories": ["Essence"],
      "domain_attributes": {...},
      "score": 0.91
    }
  ],
  "total": 5
}
```

---

## 3. Customer sync (`POST /v1/sync/customers`)

Body: `{customers: list[CanonicalCustomer]}`. Validates each customer's `profile` against the pack's `profile_schema`. Upserts to `customer` table on `(tenant_id, platform_id)`. Returns `{synced: int, failed: int, errors: list}`.

`email_hash` is the SHA-256 of the lowercased email address. The raw email is never stored.

---

## 4. Intent classifier

Before routing a widget query, the gateway classifies intent using `claude-haiku-4-5`. Result cached in Redis keyed on `sha256("intent:" + query.lower())` with a 24-hour TTL.

```python
class QueryIntent(BaseModel):
    intent: Literal["product_search", "compatibility", "routine", "faq", "other"]
    confidence: float
```

Intent directs which layer to try first:
- `product_search` → Layer 1 (vector search)
- `compatibility` → Layer 2 (rule engine)
- `routine` → Layer 2 + Layer 1 (rules + products)
- `faq` → Layer 3 (templates)
- `other` → Layer 4 (LLM direct)

---

## 5. Rule engine layer (Layer 2)

`helix/domain/rules.py` implements two operations:

**Compatibility check** — given a list of product platform IDs, extract their `key_ingredients` from `domain_attributes`, then test every pair against the pack's `compatibility_rules`. Returns any conflicts or cautions.

**Routine ordering** — given a list of products, order them by `domain_attributes.step` following the pack taxonomy's `routine_steps` sequence. Flags SPF-last violations.

`RuleEngineLayer.query()` in `layers.py` delegates to `rules.py` and returns `LayerResult(answered=True, ...)` when the query matches a compatibility or routine pattern.

---

## 6. Template layer (Layer 3)

`TemplateLayer.query()` matches the query text against a small set of known patterns using case-insensitive substring matching. Templates come from the pack's `copy/en.json`. Returns `LayerResult(answered=True, ...)` for FAQ patterns like "return policy", "shipping", "skin type quiz".

This layer is intentionally simple — the goal is to catch high-frequency zero-cost answers, not to be a sophisticated NLU system.

---

## 7. AI consultant (`POST /v1/widget/chat`)

**Auth:** `Authorization: Bearer <jwt>` — validated by `validate_widget_token()`. Token carries `tenant_id`.

**Request:**
```json
{
  "query": "What's good for dry sensitive skin under R400?",
  "customer_profile": {"skin_type": "dry", "sensitivities": ["fragrance"]}
}
```

**Flow (gateway `route_query()`):**
1. Classify intent (Haiku, cached).
2. Try Layer 1–3 in order based on intent.
3. If no layer answers: build a grounded prompt with retrieved products + customer profile, call Sonnet (Layer 4).
4. Write `usage_event` row for any LLM call (cost_usd = 0 for cached hits).
5. Return response + `source` metadata.

**Response:**
```json
{
  "response": "For dry, fragrance-sensitive skin under R400, I'd recommend...",
  "source": "llm",
  "products_referenced": ["42", "17"],
  "session_id": "..."
}
```

`source` is one of `"vector"`, `"rules"`, `"template"`, `"llm"`.

**Grounded prompt construction:**
- System: pack `prompts/system.md` (with `{brand_name}` filled in), marked `cache_control: ephemeral`.
- User context block: top-5 products from vector search + customer profile + compatibility rules from pack.
- The system prompt instructs the model to answer only from the supplied context. If context is absent, respond "I don't have that information" and offer human support. This rule is non-negotiable.

---

## 8. Routine builder (`POST /v1/widget/routine`)

**Auth:** same JWT.

**Request:**
```json
{
  "customer_profile": {"skin_type": "oily", "skin_concerns": ["acne", "pores"]},
  "budget_minor": 80000
}
```

**Flow:**
1. Vector search for products matching the profile's skin type and concerns (constructed query: `"oily acne pores routine"`).
2. Filter by `budget_minor` if provided.
3. Apply rule engine: order by `domain_attributes.step` per taxonomy, flag conflicts.
4. If fewer than 2 products found, call LLM (Sonnet) with grounded context to explain what's missing.
5. Return ordered routine steps.

**Response:**
```json
{
  "routine": [
    {"step": "cleanse", "product": {...}},
    {"step": "treat", "product": {...}},
    {"step": "moisturize", "product": {...}}
  ],
  "conflicts": [],
  "missing_steps": ["tone", "protect"],
  "llm_augmented": false
}
```

---

## 9. Usage metering

Every LLM call through the gateway writes a `usage_event` row:
```
tenant_id, model, tokens_in, tokens_out, cost_usd, endpoint
```

Cached responses write a zero-cost event (`tokens_in=0, tokens_out=0, cost_usd=0`) tagged with `endpoint="cached"` — this preserves query volume data.

---

## 10. Redis response cache

`helix/llm/cache.py` wraps `redis.asyncio`. Cache key: `sha256(model_id + ":" + system_prompt_hash + ":" + user_prompt)`. TTL: 24h for classification, 1h for generated responses.

Cache is opt-in — the gateway passes `cache_ttl` to `route_query()`. Classification and FAQ responses are always cached. Personalized consultant responses are not cached (profile context differs per user).

---

## 11. File map

**New files:**
- `services/core/helix/db/crud/customers.py`
- `services/core/helix/db/crud/products.py` — add `vector_search_products()`
- `services/core/helix/domain/__init__.py`
- `services/core/helix/domain/search.py`
- `services/core/helix/domain/rules.py`
- `services/core/helix/domain/consultant.py`
- `services/core/helix/domain/routine.py`
- `services/core/helix/llm/cache.py`
- `services/core/helix/llm/layers.py` — implement all three stub layers
- `services/core/helix/llm/gateway.py` — add `route_query()` method
- `services/core/helix/api/routers/search.py`
- `services/core/helix/api/routers/sync.py` — add `POST /v1/sync/customers`
- `services/core/helix/api/routers/widget.py` — add chat + routine endpoints

**Modified files:**
- `services/core/helix/api/app.py` — register new routers
- `services/core/helix/api/deps.py` — add `get_widget_tenant()` (JWT auth)

**New tests:**
- `test_search_endpoint.py`
- `test_customer_sync.py`
- `test_rules_engine.py`
- `test_llm_cache.py`
- `test_gateway_routing.py`
- `test_chat_endpoint.py`
- `test_routine_endpoint.py`

---

## 12. Security constraints (unchanged from Phase 0)

- Tenant isolation: every query scoped by `tenant_id` — no cross-tenant reads.
- Widget JWT: 15 min expiry, carries no secrets, scoped to one tenant.
- Grounding rule: consultant and routine endpoints instruct the model to answer only from supplied context; "I don't have that information" if context is absent.
- No PII in logs at info level — customer profile data is logged at debug only.
- Rate limiting on widget endpoints: not implemented in Phase 1 (added in Phase 2 via middleware).

---

## Cost model (Phase 1 target)

| Layer | Share | Cost/query |
|-------|-------|-----------|
| Layer 1 — vector search | ~60% | $0 |
| Layer 2 — rules | ~20% | $0 |
| Layer 3 — templates | ~10% | $0 |
| Layer 4 — LLM (Sonnet) | ~10% | ~$0.001–0.003 |
| Intent classifier (cached ~80%) | 100% | ~$0.0001 uncached |

At 1,000 queries/day: ~$31–35/month/store with caching.
