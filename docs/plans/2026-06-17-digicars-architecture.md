# Digicars — Architecture & Implementation Plan

**Date:** 2026-06-17  
**Concept:** A car dealership WooCommerce site powered by Helix AI, running on the same VPS and backend infrastructure as the existing K-Beauty tenant. Completely isolated data; shared infrastructure.

---

## 1. Multi-Tenant Isolation

Helix's data model is already multi-tenant — every DB table has `tenant_id`. Digicars is simply **Tenant B**.

| Concern | K-Beauty (Tenant A) | Digicars (Tenant B) |
|---------|--------------------|--------------------|
| Database rows | `tenant_id = kbeauty-uuid` | `tenant_id = digicars-uuid` |
| Public key | Existing | New (generated on provisioning) |
| Domain pack | `pack_id = "kbeauty"` | `pack_id = "automotive"` |
| WooCommerce site | kbeauty.yourdomain.com | digicars.yourdomain.com |
| WP plugin install | Existing install | Fresh install, own API key |
| Products | Skincare SKUs | Car listings (WC products) |
| Branding | K-beauty palette | Digicars palette (dark, professional) |

**No code changes required to isolate** — provisioning a new tenant via the existing admin API is sufficient. All queries, embeddings, chat sessions, and leads are scoped by `tenant_id` at the DB layer.

---

## 2. Domain Pack: `packs/automotive/`

Mirrors the structure of `packs/kbeauty/`. Files to create:

```
packs/automotive/
├── pack.yaml
├── product_schema.json
├── taxonomy.yaml
├── compatibility_rules.yaml
├── profile_schema.json
├── prompts/
│   ├── system.md
│   └── consultant.md
└── copy/
    └── cta.yaml
```

### `pack.yaml`

```yaml
id: automotive
version: "0.1.0"
display_name: "Automotive"
cta_type: enquire          # cart | enquire | whatsapp
enquire_fields:
  - name
  - phone
  - email
  - preferred_contact_time
```

The `cta_type: enquire` key signals to the widget JS that instead of "Add to Cart", the card CTA is "Enquire Now" which opens a lead capture form rather than calling the WC cart API.

### `product_schema.json`

Domain attributes stored in `Product.domain_attributes` (JSONB):

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "properties": {
    "make":            { "type": "string" },
    "model":           { "type": "string" },
    "year":            { "type": "integer", "minimum": 1980 },
    "condition":       { "type": "string", "enum": ["new", "used", "demo"] },
    "mileage_km":      { "type": "integer", "minimum": 0 },
    "fuel_type":       { "type": "string", "enum": ["petrol", "diesel", "hybrid", "electric", "plug-in-hybrid"] },
    "transmission":    { "type": "string", "enum": ["manual", "automatic", "semi-automatic"] },
    "body_type":       { "type": "string", "enum": ["sedan", "suv", "hatchback", "bakkie", "coupe", "convertible", "minivan", "wagon"] },
    "engine_cc":       { "type": "integer" },
    "colour":          { "type": "string" },
    "doors":           { "type": "integer", "enum": [2, 3, 4, 5] },
    "safety_rating":   { "type": "number", "minimum": 0, "maximum": 5 },
    "ncap_stars":      { "type": "integer", "minimum": 0, "maximum": 5 },
    "features":        { "type": "array", "items": { "type": "string" } },
    "price_zar":       { "type": "integer" },
    "finance_from_zar": { "type": "integer", "description": "Estimated monthly instalment at prime rate" },
    "vin":             { "type": "string" },
    "stock_number":    { "type": "string" },
    "certified_used":  { "type": "boolean" }
  },
  "required": ["make", "model", "year", "condition", "fuel_type", "transmission", "body_type"]
}
```

### `taxonomy.yaml`

```yaml
body_types:
  - sedan
  - suv
  - hatchback
  - bakkie
  - coupe
  - convertible
  - minivan
  - wagon

fuel_types:
  - petrol
  - diesel
  - hybrid
  - electric
  - plug-in-hybrid

use_cases:
  - family
  - commuter
  - off-road
  - luxury
  - first-car
  - towing
  - city

budget_bands_zar:
  - under_150k
  - 150k_to_300k
  - 300k_to_500k
  - 500k_to_800k
  - over_800k
```

### `prompts/system.md`

```markdown
You are an automotive consultant for {brand_name}. Your role is to help customers find the right vehicle for their lifestyle, budget, and needs.

**Rules:**
- Base every recommendation on the vehicle data provided in context. Never invent specifications, features, or pricing.
- Ask clarifying questions when budget, use-case, or family size is unclear — do not guess.
- When recommending a vehicle, explain specifically why it suits the customer's stated needs (e.g. "the Hilux suits your towing requirement because...").
- Mention financing availability where relevant; direct customers to the enquiry form for exact finance quotes.
- If a customer asks about a model not in the current inventory, say so clearly and offer to notify them when stock arrives.
- Never pressure. Use consultative, professional tone — this is a considered purchase, not impulse.
```

---

## 3. "Enquire" CTA System

Cars are not added to a cart — customers enquire. The CTA system must be configurable at the pack level.

### How it flows

1. Widget renders a car card with "Enquire Now" button (instead of "Add to Cart")
2. Click opens a small inline form (not a modal — keeps chat visible):
   - Name, Phone, Email, Preferred contact time
   - "Which vehicle:" pre-filled with car title + stock number
3. On submit: `POST /v1/widget/capture-lead` with `source: "car_enquiry"`, `product_platform_id`, plus the form fields
4. Backend saves to `Lead` model, fires tenant webhook to dealership CRM or email
5. Widget shows: "Thanks [Name]! One of our consultants will call you at [time]."

### Backend changes needed

- `Lead` model (new — also used for Sprint 1 lead capture feature):
  ```
  tenant_id, session_id, name, email, phone,
  preferred_contact_time, product_platform_id,
  source (chat_capture | car_enquiry | exit_intent),
  created_at
  ```
- `POST /v1/widget/capture-lead` extended to accept `product_platform_id` and `preferred_contact_time`
- Tenant webhook: POST to `helix_lead_webhook_url` (WP option) — compatible with Zapier, Make.com, CRM webhooks

### Widget JS — pack-aware CTA

The widget JS reads `pack.cta_type` from the API response metadata:

```js
if (packMeta.cta_type === 'enquire') {
  // render "Enquire Now" button + inline form
} else {
  // render "Add to Cart" button (existing flow)
}
```

Pack metadata returned on the initial `/v1/widget/chat` handshake response so no extra round trip.

---

## 4. WooCommerce Setup for Digicars

WooCommerce is used as the product/inventory CMS only — the cart/checkout flow is bypassed.

### WooCommerce configuration

- **Disable cart & checkout:** Install "WooCommerce Disable Cart and Checkout" plugin, or add to `functions.php`:
  ```php
  add_filter('woocommerce_is_purchasable', '__return_false');
  ```
- **Product type:** Use default "Simple product"; no variations needed unless tracking trim levels
- **Product attributes:** Add custom attributes for make, model, year, condition, mileage, fuel, transmission, body type — these sync into `Product.domain_attributes` via the existing sync endpoint
- **Price field:** Set to vehicle price in ZAR — syncs as `price_minor` (cents)
- **SKU field:** Use stock number (e.g. `DC-2024-VW-001`) — syncs as `platform_id`

### Helix plugin settings for digicars site

```
API URL:           https://api.yourdomain.com (same backend)
Tenant / API key:  [new key generated for digicars tenant]
Widget enabled:    ✓
CTA type:          Enquire (driven by pack, no separate setting needed)
Lead capture:      ✓
Lead webhook URL:  https://hook.make.com/... (or Zapier, CRM)
```

---

## 5. Financing FAQ Architecture

Financing questions ("What are your finance rates?", "Can I get a 72-month deal?") are answered from the FAQ knowledge base, not product search.

**Uses the FAQ Management feature (Sprint 2, Feature #2):**

- Dealership loads FAQs via admin UI:
  - "What deposit do I need?" → "We typically require 10% deposit..."
  - "Do you offer balloon payments?" → "Yes, up to 30% balloon..."
  - "What credit score do I need?" → "We work with all major banks..."
  - "How long does finance approval take?" → "24–48 hours..."
- FAQs are embedded and retrieved alongside car listings
- If a financing question is detected, AI answers from FAQ + recommends relevant vehicle

**No separate implementation needed** — FAQ CRUD (Sprint 2) covers this completely. Just need the dealership to populate their FAQs.

---

## 6. Shared vs Tenant-Specific Infrastructure

```
SHARED (one instance, serves all tenants):
  ├── PostgreSQL (pgvector) — all data, row-level tenant isolation
  ├── Redis — session cache, rate limits, keyed by tenant_id
  ├── FastAPI service — single process, tenant resolved from public_key
  ├── Celery workers — embedding jobs, keyed by tenant
  └── Nginx reverse proxy — routes api.yourdomain.com to port 8000

TENANT-SPECIFIC (per WordPress install):
  ├── digicars.yourdomain.com — separate WP + WooCommerce install
  ├── helix-connector plugin — same plugin version, own API key
  └── Theme + branding — completely independent
```

Cost implication: Digicars runs on the same VPS at zero additional infra cost. The only variable cost is Claude API tokens per query.

---

## 7. Example AI Query Handling

**Customer:** "I have a budget of R200k and I need a safe family car — I have two kids under 5"

**AI flow:**
1. Intent: `product_search`
2. Structured query built: `{"budget_max_zar": 200000, "use_case": "family", "safety_rating_min": 4, "body_type": ["suv", "hatchback", "minivan"]}`
3. Vector search on product embeddings + attribute filter
4. System prompt grounds response in actual inventory
5. Response: "For a family of four with young children and a R200k budget, the [Car A] stands out — it has a 5-star NCAP rating, 3 ISOFIX points, and comes in at R189,900. The [Car B] is also worth considering at R195k if you'd prefer more boot space."
6. Cards rendered inline with "Enquire Now" CTA

**Customer:** "What's the finance on the Golf?"

**AI flow:**
1. Retrieves Golf product → shows `finance_from_zar` estimate from `domain_attributes`
2. Retrieves finance FAQs → answers deposit/term questions
3. "Enquire Now" CTA opens form pre-filled with "VW Golf [stock number]"

---

## 8. New vs Used Car Handling

- `condition` field in `product_schema.json` distinguishes `new`, `used`, `demo`
- System prompt instructs AI to mention condition and mileage prominently for used vehicles
- Used vehicles: mention `certified_used` if true ("This is a Helix Certified Pre-Owned vehicle")
- AI never recommends a used vehicle as "new" — the schema enforces this at retrieval time

---

## 9. Implementation Phases

### Phase 1 — Pack & Tenant Setup (0.5 days)

1. Create `packs/automotive/` with all YAML/JSON files above
2. Provision digicars tenant via admin API: `POST /v1/admin/tenants` with `pack_id: "automotive"`
3. Install WooCommerce on digicars site, configure product attributes
4. Install Helix Connector, enter digicars API key

### Phase 2 — Enquire CTA (1 day)

1. Create `Lead` DB model + Alembic migration
2. Extend `POST /v1/widget/capture-lead` to accept `product_platform_id`, `preferred_contact_time`
3. Widget JS: read `pack.cta_type` from handshake response; branch to enquiry form vs cart
4. PHP: add `helix_lead_webhook_url` WP option + admin UI field

### Phase 3 — Product Sync & Search (0.5 days)

1. Load car listings into WooCommerce (can be manual or CSV import)
2. Trigger full product sync from Helix admin panel
3. Verify embeddings generated, test semantic search queries
4. Tune `prompts/system.md` for automotive tone

### Phase 4 — Financing FAQs (0.5 days, after Sprint 2 FAQ CRUD ships)

1. Use FAQ admin UI to enter financing Q&As
2. Test retrieval blending — finance FAQs should surface on finance intent queries

### Phase 5 — Frontend Polish (1 day)

1. Configure digicars branding (colours, logo, widget position) via Helix admin
2. Add `[helix_search]` shortcode to digicars homepage hero section
3. Test full user journey: search → recommendation → enquire → confirmation
4. Mobile responsiveness check (same widget, already responsive)

---

## 10. What NOT to Build

- No custom WooCommerce cart flow — disable it entirely
- No custom payment gateway integration — enquiry-only
- No separate backend service — digicars runs on the same FastAPI instance
- No duplication of the plugin codebase — same plugin, different API key

The modularity guarantee: **deleting the digicars tenant record cascades all digicars data and leaves kbeauty completely untouched.**
