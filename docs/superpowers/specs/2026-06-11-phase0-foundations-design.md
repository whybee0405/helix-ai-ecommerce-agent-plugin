# Phase 0 — Foundations Design Spec

**Date:** 2026-06-11  
**Status:** Approved  
**Scope:** All 12 Phase 0 tasks — monorepo through ADRs  
**Definition of done:** A real WooCommerce store's catalog syncs into PostgreSQL with embeddings generated, via the connector contract, with tenancy and the LLM gateway in place.

---

## 1. Monorepo scaffold

Single `pyproject.toml` at `services/core/` defines the `eshopeo` package. Ruff handles both linting and formatting (replaces Black — Ruff 0.4+ covers all Black rules). mypy runs in strict mode. pytest is the test runner.

```
services/core/
├── pyproject.toml          # eshopeo package, Ruff, mypy, pytest config
├── Dockerfile
├── eshopeo/
│   ├── __init__.py
│   ├── config.py
│   ├── api/
│   ├── domain/
│   ├── connectors/
│   ├── llm/
│   ├── packs/
│   ├── workers/
│   └── db/
└── tests/
```

`config.py` uses `pydantic-settings` — reads all configuration from environment variables, fails loudly on startup if required vars are missing. No defaults for secrets.

`.env.example` at repo root documents every required variable. Never committed with values.

---

## 2. Docker infrastructure (`infra/compose.yaml`)

Four services:

| Service | Image | Role |
|---------|-------|------|
| `db` | `pgvector/pgvector:pg16` | PostgreSQL 16 + pgvector extension |
| `redis` | `redis:7-alpine` | Celery broker + result backend + response cache |
| `api` | `services/core/Dockerfile` | FastAPI, port 8000 |
| `worker` | same Dockerfile, `celery` entrypoint | Async task execution |

All services have health checks. `api` and `worker` depend on `db` and `redis` being healthy before starting. Single `Dockerfile` at `services/core/` serves both `api` and `worker` — entrypoint is set by compose.

One command brings the full stack up:
```bash
docker compose -f infra/compose.yaml up --build
```

---

## 3. Core API skeleton

FastAPI app in `eshopeo/api/`. Routers are thin — they translate HTTP to a call into `eshopeo/domain/` or `eshopeo/connectors/` and back. No business logic in routers.

**Health check:** `GET /health` returns `{"status": "ok", "db": bool, "redis": bool}` — checks both connections. Used by compose health checks and future load balancers.

**Structured JSON logging:** every log line is a JSON object with `timestamp`, `level`, `logger`, `message`, and optional context fields. No PII at info level. Configured at startup from `LOG_LEVEL` env var.

**Settings** (`eshopeo/config.py`):

```python
class Settings(BaseSettings):
    database_url: PostgresDsn
    redis_url: RedisDsn
    anthropic_api_key: SecretStr
    voyage_api_key: SecretStr
    credential_encryption_key: SecretStr   # Fernet key, base64
    session_secret: SecretStr              # JWT signing key
    provision_key: SecretStr               # shared secret for POST /v1/tenants
    llm_model_classify: str = "claude-haiku-4-5"
    llm_model_generate: str = "claude-sonnet-4-6"
    llm_model_reason: str = "claude-opus-4-8"
    brand_name: str                        # public brand — never hardcoded
    environment: Literal["development", "production"] = "development"
```

---

## 4. Database layer

SQLAlchemy 2.0 mapped classes. Alembic for migrations. All migrations are in `eshopeo/db/migrations/`.

### Tables

**`tenant`**
```
id              UUID        PK, default gen_random_uuid()
name            TEXT        NOT NULL
platform        TEXT        NOT NULL  -- 'woocommerce' | 'shopify'
store_url       TEXT        NOT NULL
credentials_enc BYTEA       NOT NULL  -- Fernet-encrypted JSON
public_key      UUID        NOT NULL UNIQUE, default gen_random_uuid()
created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
```

**`product`**
```
id                UUID        PK
tenant_id         UUID        NOT NULL FK→tenant(id) ON DELETE CASCADE
platform_id       TEXT        NOT NULL  -- id in source store
title             TEXT        NOT NULL
description_html  TEXT
price_minor       INTEGER     NOT NULL
currency          CHAR(3)     NOT NULL
images            JSONB       NOT NULL DEFAULT '[]'
categories        JSONB       NOT NULL DEFAULT '[]'
in_stock          BOOLEAN     NOT NULL
domain_attributes JSONB       NOT NULL DEFAULT '{}'
embedding         vector(1024)          -- NULL until embed task runs
updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
UNIQUE (tenant_id, platform_id)
```

**`customer`**
```
id              UUID        PK
tenant_id       UUID        NOT NULL FK→tenant(id) ON DELETE CASCADE
platform_id     TEXT        NOT NULL
email_hash      TEXT        NOT NULL  -- SHA-256; never store raw email
profile         JSONB       NOT NULL DEFAULT '{}'  -- validated against pack profile_schema
created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
UNIQUE (tenant_id, platform_id)
```

**`order`**
```
id              UUID        PK
tenant_id       UUID        NOT NULL FK→tenant(id) ON DELETE CASCADE
platform_id     TEXT        NOT NULL
customer_id     UUID        FK→customer(id)
total_minor     INTEGER     NOT NULL
currency        CHAR(3)     NOT NULL
status          TEXT        NOT NULL
line_items      JSONB       NOT NULL DEFAULT '[]'
placed_at       TIMESTAMPTZ NOT NULL
UNIQUE (tenant_id, platform_id)
```

**`job`**
```
id              UUID        PK
tenant_id       UUID        NOT NULL FK→tenant(id) ON DELETE CASCADE
type            TEXT        NOT NULL  -- 'catalog_sync' | 'embed_batch' | etc.
status          TEXT        NOT NULL  -- 'pending' | 'running' | 'done' | 'failed'
progress        INTEGER     NOT NULL DEFAULT 0
total           INTEGER
error           TEXT
started_at      TIMESTAMPTZ
finished_at     TIMESTAMPTZ
created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
```

**`usage_event`**
```
id              UUID        PK
tenant_id       UUID        NOT NULL FK→tenant(id) ON DELETE CASCADE
model           TEXT        NOT NULL
tokens_in       INTEGER     NOT NULL
tokens_out      INTEGER     NOT NULL
cost_usd        NUMERIC(10,6) NOT NULL
endpoint        TEXT        NOT NULL  -- which gateway call
created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
```

### Migration 0001

- Enables `pgvector` extension
- Creates all six tables
- Adds HNSW index on `product.embedding`: `CREATE INDEX ON product USING hnsw (embedding vector_cosine_ops)`
- Composite index on `(tenant_id, platform_id)` for all tables with platform_id

---

## 5. Tenancy and auth

### Credential encryption

Platform credentials (WooCommerce consumer key/secret, Shopify access token) are encrypted at rest with `cryptography.Fernet`. The encryption key is in `CREDENTIAL_ENCRYPTION_KEY` env var (base64-encoded 32-byte key). Never stored in DB unencrypted.

```python
def encrypt_credentials(data: dict) -> bytes: ...
def decrypt_credentials(enc: bytes) -> dict: ...
```

### Connector authentication

Connectors authenticate with `X-eShopeo-Tenant-Key: <uuid>` header. This UUID is `tenant.public_key` — issued at provisioning, stored in the connector. A FastAPI dependency (`get_tenant`) validates the header, loads the tenant row, and returns it. Fails with 401 if missing or unknown.

### Widget session tokens

Short-lived JWTs (15 minutes, HS256, signed with `SESSION_SECRET`). Payload: `{tenant_id: str, exp: int}`. Issued by `POST /v1/widget/session` — authenticated by the connector using its tenant key. Widget sends the JWT in `Authorization: Bearer <token>` on subsequent requests. Tokens carry no secrets and are scoped to one tenant.

### Tenant-scoped queries

All data-layer functions accept a `tenant_id: UUID` parameter. A `TenantScope` context helper raises `AssertionError` at the call site if a query is attempted without a `tenant_id`. There is no code path that returns data across tenants.

---

## 6. LLM gateway — layered routing

All Claude calls go through `eshopeo/llm/`. No router, worker, or domain function calls the Anthropic SDK directly.

> **Phase 0 scope note:** The full gateway — including layered routing — is built and tested in Phase 0. The query path (Layers 1–3 serving customer queries) is not exercised until Phase 1. In Phase 0 the gateway handles only the LLM calls needed during sync (if any). Building the routing structure now means Phase 1 adds query handling without touching gateway internals.

### Model tiers

```python
class ModelTier(str, Enum):
    CLASSIFY = "classify"   # claude-haiku-4-5
    GENERATE = "generate"   # claude-sonnet-4-6
    REASON   = "reason"     # claude-opus-4-8
```

Resolved at call time from `settings.llm_model_*` — never hardcoded inside gateway logic.

### Layered routing (cost-first)

Before any LLM call, the gateway routes through four layers cheapest-first. Each layer returns a `LayerResult` with `answered: bool` and `response`. LLM fires only when all cheaper layers pass.

```
Layer 1 — Vector search (pgvector):  $0/query
  Handles product discovery queries — returns ranked product list.
  Covers ~60% of queries.

Layer 2 — Rule engine (pack rules):  $0/query
  Handles compatibility, routine-building, layering-order questions.
  Covers ~20% of queries.

Layer 3 — Template responses:        $0/query
  Handles FAQ, policy, and known-pattern questions.
  Covers ~10% of queries.

Layer 4 — LLM (Sonnet or Haiku):     ~$0.001–0.003/query
  Fires only when layers 1–3 produce no confident answer.
  Target: ~10% of queries.
```

The intent classifier (Haiku, ~$0.001) runs before routing to identify which layer to try first. This classification response is itself cached in Redis.

### Prompt caching

The system prompt and pack prompt fragments are marked with Anthropic `cache_control` (ephemeral cache). These are identical for all queries against a given tenant's store. For a 2,000-token system prompt at 1,000 queries/day, this reduces input token costs by ~80%.

### Structured output

Every LLM call requests JSON, parses the response, and validates it against a Pydantic schema. If validation fails, one repair attempt is made (send the error back to the model with the original prompt). If the repair also fails, `LLMParseError` is raised — never silently return garbage.

### Response caching

Deterministic prompts (attribute extraction, classification, FAQ answers) are cached in Redis keyed on `sha256(model + prompt_hash)`. Default TTL: 24 hours for classification, 1 hour for generated copy.

### Usage metering

Every LLM call (including cache misses) writes a `usage_event` row: `tenant_id`, `model`, `tokens_in`, `tokens_out`, `cost_usd`, `endpoint`. Cached responses write a zero-cost event (for query volume tracking). Batch API calls are tracked separately.

### Grounding rule

For any query that includes factual claims about products, ingredients, or orders: the prompt must include the retrieved context, and the system prompt instructs the model to answer only from supplied context — if context is absent, respond with "I don't have that information" and offer human support. This is a hard instruction in every prompt template, not a guideline.

---

## 7. Domain-pack loader

`eshopeo/packs/` loads and validates packs at startup. Packs are stored in `packs/<name>/`.

### Pack structure

```
packs/kbeauty/
├── pack.yaml               # id, version, display_name
├── profile_schema.json     # JSON Schema for customer profile
├── product_schema.json     # JSON Schema for domain_attributes
├── taxonomy.yaml           # concerns, categories, routine steps
├── compatibility_rules.yaml
├── prompts/
│   ├── system.md           # injected into every LLM system prompt
│   └── consultant.md       # injected for consultant queries
└── copy/
    └── en.json             # UI strings for the widget
```

### Loader

`PackLoader.load(path: Path) -> LoadedPack` validates all schemas at load time using `jsonschema`. Raises `PackValidationError` with a clear message on any malformed schema. Returns a `LoadedPack` dataclass with typed fields.

```python
@dataclass
class LoadedPack:
    id: str
    version: str
    profile_schema: dict
    product_schema: dict
    taxonomy: dict
    compatibility_rules: list[dict]
    prompts: dict[str, str]   # filename stem → content
    copy: dict[str, dict]     # locale → strings
```

Packs are loaded at API/worker startup and cached in memory. Per-tenant pack selection is stored in `tenant.domain_pack` (added in a future migration when multi-pack tenants are needed — Phase 0 all tenants use `kbeauty`).

---

## 8. Connector contract

`eshopeo/connectors/` owns the canonical models and the HTTP contract.

### Canonical models

```python
class CanonicalProduct(BaseModel):
    tenant_id: UUID
    platform: Literal["woocommerce", "shopify"]
    platform_id: str
    title: str
    description_html: str | None
    price_minor: int
    currency: str
    images: list[str]
    categories: list[str]
    in_stock: bool
    domain_attributes: dict   # validated against active pack's product_schema
    deleted: bool = False     # set to True for delete events; skips upsert, removes row

class CanonicalCustomer(BaseModel):
    tenant_id: UUID
    platform: Literal["woocommerce", "shopify"]
    platform_id: str
    email_hash: str           # SHA-256 of lowercased email
    profile: dict             # validated against pack's profile_schema

class CanonicalOrder(BaseModel):
    tenant_id: UUID
    platform: Literal["woocommerce", "shopify"]
    platform_id: str
    customer_platform_id: str | None
    total_minor: int
    currency: str
    status: str
    line_items: list[dict]
    placed_at: datetime
```

### API endpoints (`/v1/`)

**`POST /v1/tenants`** — Provisioning  
Authenticated with a shared provisioning secret (`X-eShopeo-Provision-Key`). Body: `{name, platform, store_url, credentials}`. Creates `tenant` row, encrypts credentials, returns `{tenant_id, public_key}`.

**`POST /v1/sync/products`** — Batch upsert  
Authenticated with `X-eShopeo-Tenant-Key`. Body: `{products: list[CanonicalProduct]}`. Validates each product's `domain_attributes` against the pack's `product_schema`. Upserts to `product` table (insert or update on `(tenant_id, platform_id)`). Enqueues `embed_product` Celery task for each upserted product. Returns `{synced: int, failed: int, errors: list}`.

**`POST /v1/webhooks/products`** — Single product event  
Unauthenticated route — verified by HMAC. Header `X-WC-Webhook-Signature` contains `base64(HMAC-SHA256(body, webhook_secret))`. Webhook secret is the tenant's stored credential secret, looked up by `X-eShopeo-Tenant-Id` header. Rejects with 401 on signature mismatch. Processes create/update/delete events.

**`POST /v1/widget/session`** — Issue widget token  
Authenticated with `X-eShopeo-Tenant-Key`. Returns `{token: str, expires_in: 900}`.

---

## 9. WooCommerce connector (PHP plugin)

`connectors/woocommerce/` — a WordPress plugin following WordPress Plugin Standards. PHP 8.0+. No Composer dependencies — uses the WordPress HTTP API (`wp_remote_post`, `wp_remote_get`) for all outbound calls.

### On activation

1. Reads WooCommerce consumer key/secret from plugin settings.
2. Calls `POST /v1/tenants` with `{name: get_bloginfo('name'), platform: 'woocommerce', store_url: site_url(), credentials: {consumer_key, consumer_secret}}`.
3. Stores returned `public_key` and `tenant_id` in WordPress options (`eshopeo_public_key`, `eshopeo_tenant_id`).
4. Registers WooCommerce webhooks for `product.created`, `product.updated`, `product.deleted` pointing at `{ESHOPEO_API_URL}/v1/webhooks/products`.
5. Stores the webhook delivery secret in options (`eshopeo_webhook_secret`).

### Settings page

WordPress admin settings page under WooCommerce → eShopeo. Fields: API URL, consumer key/secret. Shows: connection status, last sync time, product count synced.

### Full catalog sync

Triggered from settings page ("Sync now") or on activation. Paginates WooCommerce REST API (`/wp-json/wc/v3/products`, 100 per page). For each page: translates products → `CanonicalProduct[]`, sends `POST /v1/sync/products`. Shows progress in admin. Handles WooCommerce API errors gracefully (logs, continues).

### Translation: WooCommerce → CanonicalProduct

```php
function eshopeo_translate_product(array $wc_product): array {
    // price: WC stores as string decimal; convert to minor units (cents/cents)
    $price_minor = (int) round((float) $wc_product['price'] * 100);
    
    // domain_attributes: extract from WC attributes and meta
    $domain_attrs = eshopeo_extract_domain_attributes($wc_product);
    
    return [
        'tenant_id'         => get_option('eshopeo_tenant_id'),
        'platform'          => 'woocommerce',
        'platform_id'       => (string) $wc_product['id'],
        'title'             => $wc_product['name'],
        'description_html'  => $wc_product['description'] ?: null,
        'price_minor'       => $price_minor,
        'currency'          => get_woocommerce_currency(),
        'images'            => array_column($wc_product['images'], 'src'),
        'categories'        => array_column($wc_product['categories'], 'name'),
        'in_stock'          => $wc_product['stock_status'] === 'instock',
        'domain_attributes' => $domain_attrs,
    ];
}
```

`eshopeo_extract_domain_attributes()` maps WooCommerce product attributes (`pa_skin-type`, `pa_concerns`, etc.) and meta fields directly to the pack schema fields — no AI call needed. Pure array mapping.

### Webhook forwarding

On receiving a WooCommerce product webhook: translates to `CanonicalProduct`, signs the outbound request with the stored webhook secret (HMAC-SHA256), forwards to `/v1/webhooks/products`. Handles delete events by sending `{platform_id, deleted: true}`.

---

## 10. Embedding pipeline

### Voyage AI

Model: `voyage-3-lite` (1024-dim, $0.02/1M tokens). API: `POST https://api.voyageai.com/v1/embeddings`. Input text: `"{title} | {', '.join(categories)} | {json.dumps(domain_attributes)}"` — title and structured attributes, not raw HTML.

### Celery task

```python
@celery_app.task(bind=True, max_retries=3, default_retry_delay=60)
def embed_product(self, tenant_id: str, product_id: str) -> None: ...
```

Enqueued after every product upsert. Calls Voyage AI, updates `product.embedding`. Retries up to 3× on transient API errors with 60-second backoff.

### Batch re-embed

`embed_product_batch` task handles initial sync: takes a list of product IDs, calls Voyage AI in batches of 128 (API limit), updates all at once. Used by the full catalog sync flow — a Celery chord: sync all products → then `embed_product_batch`.

---

## 11. kbeauty pack seed

`packs/kbeauty/profile_schema.json`:
```json
{
  "type": "object",
  "properties": {
    "skin_type": {"type": "string", "enum": ["dry", "oily", "combination", "normal", "sensitive"]},
    "skin_concerns": {"type": "array", "items": {"type": "string", "enum": ["acne", "aging", "brightening", "hydration", "pores", "redness", "texture"]}},
    "sensitivities": {"type": "array", "items": {"type": "string", "enum": ["fragrance", "alcohol", "silicone", "sulfates", "parabens"]}},
    "budget_zar": {"type": "integer", "minimum": 0}
  },
  "required": ["skin_type"]
}
```

`packs/kbeauty/product_schema.json`:
```json
{
  "type": "object",
  "properties": {
    "skin_types": {"type": "array", "items": {"type": "string"}},
    "concerns_targeted": {"type": "array", "items": {"type": "string"}},
    "key_ingredients": {"type": "array", "items": {"type": "string"}},
    "spf": {"type": "integer"},
    "ph_level": {"type": "number"},
    "step": {"type": "string", "enum": ["cleanse", "tone", "treat", "moisturize", "protect", "mask"]}
  },
  "required": ["skin_types", "concerns_targeted"]
}
```

`packs/kbeauty/pack.yaml`:
```yaml
id: kbeauty
version: "0.1.0"
display_name: "K-Beauty"
```

Minimal `taxonomy.yaml`:
```yaml
concerns: [acne, aging, brightening, hydration, pores, redness, texture]
routine_steps: [cleanse, tone, treat, moisturize, protect, mask]
categories: [cleanser, toner, serum, moisturizer, sunscreen, mask, eye_cream, exfoliant]
```

Minimal `compatibility_rules.yaml` (3 seed rules — enough to exercise the rules engine in Phase 1):
```yaml
- id: retinol_aha
  description: "Do not layer retinol with AHA/BHA on the same night"
  type: conflict
  ingredients: [retinol, glycolic acid, lactic acid, salicylic acid]

- id: vitamin_c_niacinamide
  description: "High-concentration vitamin C and niacinamide may reduce efficacy — use AM/PM"
  type: caution
  ingredients: [ascorbic acid, niacinamide]

- id: spf_last
  description: "SPF must always be the final step in a morning routine"
  type: order
  step: protect
  position: last
```

---

## 12. ADRs

| File | Decision |
|------|----------|
| `docs/adr/0001-hosted-core-vs-self-contained-plugin.md` | Hosted multi-tenant core + thin connectors |
| `docs/adr/0002-postgresql-pgvector-single-datastore.md` | PostgreSQL + pgvector; no separate vector DB |
| `docs/adr/0003-domain-pack-declarative-format.md` | Packs as declarative data + thin rules module |
| `docs/adr/0004-voyage-ai-embeddings.md` | Voyage AI `voyage-3-lite` for product embeddings |
| `docs/adr/0005-llm-gateway-layered-routing.md` | Layered routing (vector → rules → templates → LLM); prompt caching; batch API for non-real-time |

---

## Cost model summary

| Phase | Per-store cost |
|-------|---------------|
| Phase 0 setup (500 products) | ~$0 — attribute extraction is scripted, embeddings ~$0.002 |
| Phase 1+ at 1,000 queries/day (full AI) | ~$315/mo |
| Phase 1+ with layered routing + caching | ~$31–35/mo |
| Saving | ~89% |

Layered routing target: Layer 1–3 handle ~90% of queries at $0; LLM fires for ~10%. Per-tenant `usage_event` table gives the data to tune this threshold per store over time.
