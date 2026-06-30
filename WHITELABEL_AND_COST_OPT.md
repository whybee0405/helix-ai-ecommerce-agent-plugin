# Whitelabel & Cost Optimization Release

**Branch:** `whitelabel-and-cost-opt`
**Connector version:** `0.4.0`
**Date:** 2026-06-15

Two big pieces in one release:

1. **Whitelabel** — every client now has dynamic brand identity (name, colors, copy, chips, custom CSS) stored centrally on the VPS and edited from WP admin. One codebase, many brands. No per-client forks.
2. **Cost optimization** — aggressive layering (template/rules → semantic cache → smart-routed model) plus per-tenant budgets, history compression, and abort-on-disconnect. Expected: ~60–70% lower LLM cost at same or better p95 latency.

---

## What's new for end clients

### New WP admin pages
- **WooCommerce → eShopeo Branding** — brand identity, colors, copy, suggestion chips, industry preset, custom CSS
- **WooCommerce → eShopeo Usage** — today's spend, daily budget bar (green/amber/red), tier, 30-day cost breakdown

### Five industry presets
Pick one on provision (or via "Apply preset" in admin):
- `general` — generic purple, broad chips
- `skincare` — pink/coral, serum/moisturiser focused
- `electronics` — blue/cyan, spec-fluent
- `fashion` — black/gold, style-focused
- `automotive` — red, dealership-focused (SUVs, bakkies, financing, test drives)

### Dynamic widget rendering
- Brand name, tagline, headline, placeholders, "Open in chat" CTA, greeting, error strings — all substituted from branding payload via `{{brand_name}}` / `{{brand_short_name}}` tokens
- Primary/secondary/accent colors flow into CSS variables (`--hx-primary`, `--hx-secondary`, `--hx-accent`, `--hx-gradient`)
- Suggestion chips rendered from `branding.suggestion_chips` (clickable, pre-fills query)
- Custom CSS textarea (sanitised, scoped to `#hx-*` / `.hx-*` selectors)

---

## Cost optimization changes

| Layer | Change | Expected impact |
|---|---|---|
| **Cache** | Per-tenant Redis cache key namespaced by `t={tenant_id}:bv={version}` so prompt/response caches are isolated and self-invalidate on branding edits | Correctness |
| **Semantic cache** | New `SemanticCache` (Redis sorted-set + cosine, threshold 0.93, intent-aware TTL 1h–24h) using local `bge-small-en-v1.5` embeddings. Bounded at 256 entries per namespace | −40 to −55% tokens |
| **History compression** | Last 4 turns verbatim + Haiku-generated summary of older turns, cached 24h per `(conversation_id, head_hash)` | −15% on long convos |
| **Tool-calling style** | Prompt instructs LLM to emit product IDs in `product_ids_referenced` (UI renders cards); `max_tokens` cut 1024 → 768 | −20 to −30% output |
| **Smart routing** | `_pick_model_for_intent`: FAQ + small-candidate product_search → Haiku; compatibility/routine → Sonnet | −20% on tier-overspend |
| **FAQ pre-warming** | Celery task runs preset's `faq_seed_queries` through the full stack on provision + preset-apply + weekly. First-touch visitors hit warm cache | −10 to −15% on first-touch |
| **Budgets** | Rolling 24h spend, tier defaults free $0.50 / starter $5 / pro $50 / enterprise $500. Soft cap at 80% (force Haiku); hard cap (cache-only friendly fallback) | Bill predictability |
| **Rate limit** | Per-tenant Redis token bucket (30 burst, 0.5/sec) → 429 on abuse | Abuse protection |
| **Abort on disconnect** | Server checks `Request.is_disconnected()` mid-stream; widget JS uses `AbortController` on new input / Escape / panel close | −10 to −20% on interrupts |

---

## API surface

### Public (widget)
- `GET /v1/widget/branding?key=<public_key>` — branding payload + ETag, `Cache-Control: public, max-age=60, stale-while-revalidate=600`

### Admin (HMAC-signed)

Headers required on every admin request:
```
X-eShopeo-Tenant-Id: <tenant uuid>
X-eShopeo-Timestamp: <unix epoch seconds>
X-eShopeo-Signature: base64( HMAC-SHA256(admin_secret, timestamp + "." + raw_body) )
```
Timestamp window: ±300 s.

- `GET  /v1/admin/branding` — current branding
- `POST /v1/admin/branding` — partial update (any field optional)
- `POST /v1/admin/branding/apply-preset` — `{ "preset_id": "skincare" }`, overwrites everything
- `GET  /v1/admin/presets` — list available presets
- `GET  /v1/admin/usage?days=30` — daily breakdown, today's spend, tier, mode

### Provision bootstrap (provision_key)
- `POST /v1/tenants` — provision now generates `admin_secret`, returns `{ tenant_id, public_key, admin_secret }`. Optional `preset_id` field
- `POST /v1/tenants/{tenant_id}/admin-secret` — bootstrap admin_secret for existing tenants (idempotent)

---

## Schema

### Migration `0006_branding.py`

Adds to `tenant`:
- `branding` JSONB NOT NULL DEFAULT `'{}'`
- `branding_version` INT NOT NULL DEFAULT `1`
- `tier` VARCHAR(32) NOT NULL DEFAULT `'free'`
- `daily_budget_usd` NUMERIC(10,4) NULL

Backfill behaviour: existing tenants get an empty `branding = {}`; the `BrandingService` lazy-merges preset defaults on read so they don't see breakage. On first save in WP admin, the merged value is persisted.

### `credentials_enc` additions
The encrypted Fernet blob now also stores `admin_secret`. For tenants provisioned before this release, hit `POST /v1/tenants/{id}/admin-secret` once (the WP plugin does this automatically the first time the user opens the Branding tab).

---

## Branding payload shape

```jsonc
{
  "preset_id": "skincare",
  "brand_name": "Glow Advisor",
  "brand_short_name": "Glow",
  "tagline": "Skincare guidance, personalised",
  "avatar_url": "https://example.com/logo.png",
  "primary_color": "#EC4899",
  "secondary_color": "#F472B6",
  "accent_color": "#FBCFE8",
  "headline_text": "Ask {{brand_name}} about your skincare routine",
  "search_placeholder": "Skin concerns? Routine questions? Ask here…",
  "chat_placeholder": "Tell me about your skin…",
  "footer_cta_label": "Open in chat",
  "greeting": "Hi! I'm {{brand_name}}. Tell me about your skin type…",
  "suggestion_chips": [
    { "label": "Show me serums",  "query": "Show me serums for hydration" }
  ],
  "faq_seed_queries": ["…"],
  "tone": "warm, expert, and reassuring",
  "locale": "en-ZA",
  "currency": "ZAR",
  "custom_css": "#hx-sb-inner { box-shadow: …; }"
}
```

### Custom CSS rules
- Allowed selectors: `#hx-*`, `.hx-*`, `@media`, `@keyframes`, `@supports`, `@font-face`, `:root`
- Forbidden: `@import`, `behavior:`, `expression(`, `javascript:`, `vbscript:`, `data:text/html`, `position:fixed`, `<script>`, `</style>`
- Limit: 8000 characters
- Sanitiser silently drops disallowed rules; UI tells the user if the saved value differs

---

## New / modified files

### Backend (`services/core/`)
- `eshopeo/branding/{__init__,schemas,presets,service,css_sanitizer}.py`
- `eshopeo/branding/presets/{general,skincare,electronics,fashion,automotive}.json`
- `eshopeo/api/routers/branding.py`
- `eshopeo/llm/{embeddings,semantic_cache,history,budget}.py`
- `eshopeo/workers/tasks/faq_warm.py`
- `eshopeo/db/migrations/versions/0006_branding.py`
- Modified: `eshopeo/db/models.py`, `eshopeo/api/{app,routers/tenants,routers/widget}.py`, `eshopeo/domain/consultant.py`, `eshopeo/llm/{cache,gateway}.py`, `eshopeo/workers/celery_app.py`, `pyproject.toml`

### Connector (`connectors/woocommerce/`)
- `includes/class-eshopeo-branding.php` (new — admin Branding tab)
- `includes/class-eshopeo-cost-dashboard.php` (new — Usage page)
- Modified: `eshopeo-connector.php`, `includes/class-eshopeo-admin.php`, `includes/class-eshopeo-api-client.php`

---

## New runtime dependency

`sentence-transformers>=3.0.0` + `numpy>=1.26.0`.

**First worker boot** downloads `BAAI/bge-small-en-v1.5` (~130 MB) from HuggingFace. Cached at `~/.cache/huggingface/`. Worth mounting as a volume in `infra/compose.yaml` if you redeploy often:

```yaml
worker:
  volumes:
    - hf-cache:/root/.cache/huggingface
volumes:
  hf-cache: {}
```

Container image size grew by ~500 MB (torch is the bulk). If memory pressure becomes a problem, consider running embeddings only in the worker (already the case) and using the API container only as a thin orchestrator.

---

## Tier defaults (per day, USD)

| Tier | Limit | Soft cap (80%) | Hard cap (100%) |
|---|---|---|---|
| `free` | $0.50 | switch to Haiku | cache-only |
| `starter` | $5.00 | switch to Haiku | cache-only |
| `pro` | $50.00 | switch to Haiku | cache-only |
| `enterprise` | $500.00 | switch to Haiku | cache-only |

Override per tenant via `tenant.daily_budget_usd` column. Tier is settable directly on the tenant row (admin-only, no endpoint yet — set via DB or future admin API).

---

## Deploy

```bash
./deploy.sh
```

What it does:
1. `git pull` (now on `whitelabel-and-cost-opt` branch)
2. Rebuilds `api` + `worker` images (~5–10 min — first time `torch`/`sentence-transformers` install)
3. Brings up `db` + `redis`, waits for db health
4. Runs `alembic upgrade head` → applies migration 0006
5. Force-recreates `api` + `worker` containers
6. Prunes old images

Once API is up, the WP plugin auto-update mechanism will offer v0.4.0 to each installed connector.

### After deploy, in WordPress
1. **Updates** → "eShopeo Connector v0.4.0" → click **Update Now**
2. Open **WooCommerce → eShopeo Branding** — admin secret bootstraps automatically on first load
3. Tweak brand name / colors / chips / custom CSS, or click "Apply preset"
4. Open **WooCommerce → eShopeo Usage** to see live cost tracking

### On the storefront
Existing pages using `[eshopeo_search]` keep working — branding now flows through automatically.

---

## Rollback

If anything goes sideways:
```bash
git checkout master
./deploy.sh
```
The 0006 migration's `downgrade()` cleanly drops the four new columns. Existing widget code on master doesn't read them, so rollback is a no-op for existing tenants.

**Caveats:**
- Tenants provisioned during the new release will have `admin_secret` in `credentials_enc` — that's a no-op extra dict key, doesn't break anything
- WP plugin v0.4.0 stays installed; uninstalling triggers `delete_option('eshopeo_admin_secret')` cleanup
- Semantic cache keys are TTL'd so they'll evict on their own

---

## Verification checklist (post-deploy)

```bash
# 1. Manifest serves new version
curl -s https://eshopeo.cloudia.co.za/v1/plugin/manifest | grep '"version"'

# 2. Branding endpoint returns sane JSON + ETag
curl -isv "https://eshopeo.cloudia.co.za/v1/widget/branding?key=<a_public_key>" | grep -i 'etag\|primary_color'

# 3. Admin presets list works (no auth needed for shape check — auth will 401)
curl -s https://eshopeo.cloudia.co.za/v1/admin/presets

# 4. Migration applied
docker compose -f infra/compose.yaml exec api alembic current

# 5. Worker picked up new tasks
docker compose -f infra/compose.yaml logs worker --tail 50 | grep faq_warm
```

In WP admin:
- eShopeo Branding page loads and shows current values
- Color picker / media picker work
- "Apply preset" warning shows, applies cleanly
- eShopeo Usage shows tier, today's spend, daily breakdown

In the storefront:
- Search bar renders with branded colors / headline / chips / placeholder
- Clicking a chip pre-fills + submits
- Search response renders with brand-substituted error if backend unreachable
- Custom CSS (if set) takes effect

---

## Open follow-ups (not in this release)

These are queued for a later iteration — none block production use:

1. **Real Anthropic token streaming.** `LLMGateway.stream_text` exists but `/chat/stream` still chunks a completed response. Wiring is straightforward; needs the consultant pipeline to support a streaming `RouteResult`.
2. **Logo upload to VPS.** Today logos are URLs (typically WP media library). Pushing them to S3/VPS volume centralises but adds complexity.
3. **A/B testing variants.** Branding variants per visitor — easy to bolt on once branding lives in JSONB.
4. **Shadow DOM widget.** For deeper CSS isolation per client. Bigger refactor; do when client CSS conflicts become a real problem.
5. **Cost dashboard charts.** Current page is table-based; a sparkline / line chart would help.
6. **Admin tier-change endpoint.** Currently you'd update `tenant.tier` directly in the DB. An admin endpoint for upgrade/downgrade would be cleaner.

---

## Quick reference: HMAC signing example (PHP / WP)

The connector does this for you, but for reference:

```php
$timestamp = (string) time();
$body      = wp_json_encode( $patch );
$payload   = $timestamp . '.' . $body;
$signature = base64_encode( hash_hmac( 'sha256', $payload, $admin_secret, true ) );

wp_remote_post( $api_url . '/v1/admin/branding', [
    'headers' => [
        'Content-Type'      => 'application/json',
        'X-eShopeo-Tenant-Id' => $tenant_id,
        'X-eShopeo-Timestamp' => $timestamp,
        'X-eShopeo-Signature' => $signature,
    ],
    'body' => $body,
] );
```

Backend verifies by reconstructing `payload`, computing the HMAC with the stored `admin_secret`, and comparing with `hmac.compare_digest`.

---

## Contact

Questions or issues — check the `services/core/eshopeo/` source for the relevant module:
- Branding: `eshopeo/branding/`
- Cost layers: `eshopeo/llm/{cache,semantic_cache,history,budget,embeddings}.py`
- Routing: `eshopeo/llm/gateway.py` (`_pick_model_for_intent`, `route_query`)
- WP admin: `connectors/woocommerce/includes/class-eshopeo-branding.php` + `class-eshopeo-cost-dashboard.php`
