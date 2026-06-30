# MASTER_PROMPT.md — eShopeo Commerce Intelligence

> **What this file is.** This is the single source of truth for building eShopeo. Any
> AI coding agent (Claude Code or otherwise) or human contributor reads this first,
> in full, before writing a line of code. It defines the product, the architecture,
> the stack, the build order, the rules of engagement, and the working protocol.
>
> **Codename:** `eshopeo` (the public brand is undecided — do not hardcode a marketing
> name anywhere; read it from config/env so it can be set later).

---

## 0. How to work in this repo (read this every session)

1. **Read `docs/PROGRESS.md` first, every session, before doing anything else.** It is
   the running state of the project: what's done, what's in flight, what's blocked,
   and what's next. Treat it as authoritative over your own assumptions.
2. **Pick the next unstarted task** from the current phase in `PROGRESS.md` unless the
   human directs otherwise. Do not jump phases.
3. **Update `docs/PROGRESS.md` as you go** — when you start a task, when you finish one,
   when you make a decision, when you hit a blocker. Never end a working session
   without leaving `PROGRESS.md` reflecting reality. This is non-negotiable: continuity
   across sessions depends entirely on it.
4. **Log every non-trivial architectural decision** as a short ADR in `docs/adr/`
   (see §10) and reference it from `PROGRESS.md`.
5. **Keep `README.md` accurate.** If you change setup steps, env vars, or commands,
   update the README in the same change.
6. **Write code a thoughtful senior engineer would write — not code that looks
   AI-generated.** See §9. This is a hard requirement, not a preference.

---

## 1. Product in one paragraph

eShopeo is a hosted, multi-tenant AI commerce-intelligence suite that plugs into both
**WooCommerce** and **Shopify** stores through thin connectors. The intelligence lives
in a central backend, never in the store. The first vertical is **K-beauty**, but the
domain knowledge is isolated into a swappable **domain pack**, so the same engine can
serve other verticals (e.g. automotive parts) by swapping the pack, not the code. The
suite raises conversion and AOV through guided selling (an AI consultant + routine/kit
builder), semantic product discovery, product-page intelligence, conversational
support, and retention automation.

## 2. The core architectural decision (do not relitigate)

One product, **one backend codebase**, **one shared widget layer**, **two thin platform
connectors**, and a **domain pack that is data/config, not code**. The intelligence
(LLM orchestration, profiles, embeddings, recommendation/compatibility reasoning) is a
hosted multi-tenant service. Connectors only: authenticate, sync catalog/customers/
orders, inject the widget, and optionally write content back. This is forced by Shopify
(apps cannot run arbitrary backend logic in-store) and is what makes the vertical-swap
story possible. See `docs/adr/0001-hosted-core-vs-self-contained-plugin.md`.

## 3. Stack (fixed unless an ADR overrides it)

- **Core service:** Python 3.12, FastAPI, Pydantic v2, SQLAlchemy 2.0, Alembic.
- **Datastores:** PostgreSQL 16 with the `pgvector` extension (relational data **and**
  embeddings in one place — do not add a separate vector DB before it's proven
  necessary). Redis for cache, sessions, and the Celery broker/result backend.
- **Async work:** Celery (in-app jobs: sync, embedding, bulk generation, scheduling).
  `n8n` is permitted for *external* marketing/lifecycle automations only — never for
  core request paths.
- **LLM:** Anthropic Claude API via a single internal gateway (§6). Tiered usage —
  Haiku-class for classification/extraction, Sonnet-class for generation and reasoning,
  Opus-class only where complex multi-step reasoning earns its cost. **Pin exact model
  IDs from `docs.claude.com`; never assume an ID from memory.**
- **Storefront widget:** Preact + Vite, compiled to a single self-contained custom
  element (`<eshopeo-widget>`) plus a small loader script. Must be framework-agnostic at
  the page level (works dropped into any theme), styled in a shadow DOM to avoid CSS
  collisions.
- **Merchant dashboard:** React + Vite + TypeScript + Tailwind + shadcn/ui.
- **WooCommerce connector:** PHP WordPress plugin following WordPress Plugin Standards.
- **Shopify connector:** Remix app on Shopify's official template; Theme App Extension
  injects the widget; Admin GraphQL API for sync.
- **Billing:** PayFast (South Africa) for Woo/direct tenants; Shopify Billing API for
  Shopify-native tenants. Per-tenant LLM usage metering from day one.
- **Infra:** Docker + `compose.yaml` for local dev; deployable to a container host
  (Hetzner target). Structured JSON logging; Sentry for errors; a usage/cost table for
  observability.

## 4. Repository layout

```
eshopeo/
├── README.md
├── docs/
│   ├── MASTER_PROMPT.md          # this file
│   ├── PROGRESS.md               # living state — read + update every session
│   ├── LEARNING.md               # mentor curriculum (keep in sync with the build)
│   └── adr/                      # one short markdown file per decision
├── infra/
│   ├── compose.yaml
│   └── docker/
├── services/
│   ├── core/                     # Python package `eshopeo`; API + worker share it
│   │   ├── eshopeo/
│   │   │   ├── api/              # FastAPI routers (thin; delegate to domain/)
│   │   │   ├── domain/          # entities + business logic (no framework imports)
│   │   │   ├── connectors/      # connector contract + server-side sync orchestration
│   │   │   ├── llm/             # Claude gateway, prompt templates, structured output
│   │   │   ├── packs/           # domain-pack loader + base schema/validation
│   │   │   ├── workers/         # Celery tasks
│   │   │   ├── db/              # SQLAlchemy models + Alembic migrations
│   │   │   └── config.py
│   │   ├── tests/
│   │   └── pyproject.toml
│   ├── dashboard/                # merchant admin (React/Vite/Tailwind)
│   └── widget/                   # embeddable storefront bundle (Preact)
├── connectors/
│   ├── woocommerce/              # thin PHP plugin
│   └── shopify/                  # thin Remix app
└── packs/
    ├── kbeauty/                  # first domain pack (data + rules + prompt fragments)
    └── motorspares/              # added in Phase 5 to prove the engine is agnostic
```

Keep `domain/` free of FastAPI/SQLAlchemy imports where practical — business logic
should be testable without a web server or database. The `api/` layer is thin glue.

## 5. The Connector Contract (the agnostic boundary)

Both connectors implement the same contract against the core API. The core must never
contain a branch on "is this Woo or Shopify" — it only knows canonical models.

**Connector responsibilities**
1. **Provisioning / auth handshake** → register the store as a tenant, store
   credentials server-side, receive a tenant public key.
2. **Catalog sync** → full sync on install, then incremental via platform webhooks
   (product create/update/delete). Connector translates platform data into
   `CanonicalProduct`.
3. **Customer + order sync** → for profiles, replenishment, abandonment.
4. **Widget injection** → load the widget bundle on the storefront, pass the tenant
   public key, request a short-lived scoped session token from the core.
5. **Content write-back (optional)** → push generated descriptions/SEO back to the
   platform on merchant approval.

**Canonical models (core-owned, platform-neutral)**

```python
class CanonicalProduct(BaseModel):
    tenant_id: UUID
    platform: Literal["woocommerce", "shopify"]
    platform_id: str            # id in the source store
    title: str
    description_html: str | None
    price_minor: int            # integer minor units; currency separate
    currency: str               # ISO 4217, e.g. "ZAR"
    images: list[str]
    categories: list[str]
    in_stock: bool
    domain_attributes: dict      # validated against the active pack's product schema
    # embedding stored separately in pgvector, keyed by (tenant_id, platform_id)
```

`CanonicalCustomer` and `CanonicalOrder` follow the same pattern. `domain_attributes`
is where pack-specific fields live (e.g. `ingredients`, `skin_concerns_targeted` for
K-beauty; `fitment`, `oem_numbers` for auto parts) — the core stores and indexes it
generically and the pack interprets it.

The contract is a documented, versioned REST interface (`/v1/...`) plus a webhook
schema. Adding a third platform later must require **only** a new connector — zero core
changes.

## 6. The LLM gateway (single choke point)

All Claude calls go through `eshopeo.llm`. No router, task, or service calls the Anthropic
SDK directly. The gateway provides:
- Model-tier selection (`classify` / `generate` / `reason`) mapped to current pinned
  model IDs in config.
- Prompt templates assembled from a base system prompt **+ the active domain pack's
  prompt fragments** + runtime context (the customer profile, retrieved products).
- Structured output: request JSON, parse defensively, validate against a Pydantic
  schema, one bounded repair retry, then fail loudly — never silently return garbage.
- Retries with backoff, timeouts, and **per-tenant usage + cost metering** written to
  the DB on every call.
- A hard rule: **retrieval-grounded answers only** for anything factual about products,
  orders, or ingredients. The model answers from supplied context; if context is
  missing it says so and offers a fallback (human support). No hallucinated product
  claims, ever.

## 7. The domain-pack system

A pack is **declarative data + a thin rules module**, loaded per tenant at runtime:
- `profile_schema` — the structured customer profile (K-beauty: skin type, concerns,
  sensitivities, budget).
- `product_schema` — required/optional `domain_attributes` and how to extract them.
- `taxonomy` — concerns, categories, the routine/solution step ontology.
- `compatibility_rules` — the rules engine inputs (K-beauty: ingredient conflicts,
  layering order; auto parts: fitment matching). Express as data where possible; only
  truly procedural logic goes in code.
- `prompts/` — prompt fragments injected into the gateway (tone, domain vocabulary,
  guardrails).
- `copy/` — UI strings for the widget, localizable.

The engine code must contain **zero K-beauty literals**. If you find yourself writing
`if "retinol"` in `services/core`, stop — that belongs in `packs/kbeauty`.

## 8. Build phases (the plan `PROGRESS.md` tracks against)

- **Phase 0 — Foundations.** Monorepo, `compose.yaml`, core API skeleton, tenancy +
  auth, DB + migrations, the LLM gateway, the domain-pack loader, and the WooCommerce
  connector doing catalog sync end to end. Definition of done: a real Woo store's
  catalog syncs into Postgres with embeddings generated.
- **Phase 1 — Conversion core.** Semantic search, AI Consultant, Routine/Kit Builder,
  PDP "will this work for me." This is the value spine; ship it well before anything
  else. Widget renders these on a live store.
- **Phase 2 — Content + support.** Bulk description/SEO generation (with write-back),
  review synthesis, grounded support agent, on-page Q&A.
- **Phase 3 — Retention.** Cart-abandonment recovery, replenishment reminders,
  lifecycle messaging, WhatsApp channel.
- **Phase 4 — Shopify parity + commercialization.** Shopify connector to full parity,
  merchant dashboard, billing (PayFast + Shopify), analytics/insights digest.
- **Phase 5 — Prove agnosticism.** Author the `motorspares` pack and stand the suite up
  on a second store with no core changes. If core changes are needed, that's a bug in
  the abstraction — fix the abstraction.

Each phase has its task breakdown maintained in `PROGRESS.md`, not here.

## 9. Code quality — "human-written" mandate (hard requirement)

The goal is a codebase indistinguishable from one a strong human team wrote over months.
Specifically:

- **Comment *why*, never *what*.** No comments that restate the code
  (`# increment counter`). No docstrings that just echo the signature. Comment intent,
  trade-offs, and non-obvious constraints.
- **No AI tells.** No emoji in code, commits, or logs. No "Here is the function that…"
  narration. No banner-comment dividers. No exhaustive step-by-step comments. No
  defensive `try/except` wrapped around everything "just in case."
- **Idiomatic and consistent.** Black + Ruff for Python; ESLint + Prettier for TS/JS;
  WordPress Coding Standards for the PHP plugin. Naming follows each language's
  conventions and stays consistent across files. Pick one way to do a thing and do it
  that way everywhere.
- **No premature abstraction and no over-engineering.** Don't build a plugin framework
  for a problem that has one case. Don't add config for things that never vary.
  Introduce abstraction when the second real use appears (the connector and pack
  boundaries are deliberate exceptions — they're requirements).
- **Small modules, real functions.** No god-files. Functions do one thing. If a file
  passes ~400 lines, question it.
- **Errors are handled deliberately,** not swallowed. Fail loudly in development, degrade
  gracefully in production with logging.
- **Tests are real.** Unit-test `domain/` logic and the compatibility rules; integration-
  test the connector contract and the LLM gateway (with the model mocked). Don't write
  tests that assert `True == True` to inflate coverage.
- **Commits look like a human's.** Conventional Commits (`feat:`, `fix:`, `refactor:`,
  `test:`, `docs:`, `chore:`), one logical change per commit, present-tense imperative
  subject under ~72 chars, body explaining *why* when not obvious. Don't dump a whole
  phase in one commit.
- **Dependencies:** prefer well-maintained, widely-used libraries over hand-rolling;
  don't pull a dependency for something a few lines solve.
- **Secrets** never in code, logs, or commits — only env/secret store.

If a change would make the code look generated, don't make it that way; make it the way
a careful human would.

## 10. Decisions log (ADRs)

Every non-trivial decision (a library choice, a schema shape, a boundary) gets a short
file in `docs/adr/` named `NNNN-short-title.md` with: context, the decision, the
alternatives considered, and the consequences. Reference the ADR number from
`PROGRESS.md`. Seed file `0001` records the hosted-core decision in §2.

## 11. Security & multi-tenancy (always on)

- **Tenant isolation is enforced at the data layer**, not just the API. Every query is
  scoped by `tenant_id`; there is no code path that can read across tenants.
- Platform credentials and API keys are encrypted at rest.
- Widget session tokens are short-lived, scoped to one tenant, and carry no secrets.
- Validate and sanitize everything coming from a storefront or webhook (treat it as
  hostile). Verify webhook signatures (Shopify HMAC, Woo secret).
- Rate-limit public widget endpoints per session and per tenant.
- Never log PII or prompt contents containing PII at info level.

## 12. Definition of done (per task)

A task is done when: it works end-to-end against a real store or a realistic fixture;
it has tests; it passes lint/format/type checks; `PROGRESS.md` is updated; any decision
is captured in an ADR; the README still reflects reality; and `LEARNING.md` has been
extended if the task introduced a concept the human should later understand (§13).

## 13. Keep the learning file alive

`docs/LEARNING.md` is the human owner's curriculum for eventually rebuilding this without
agents. When you implement a subsystem that teaches a concept (RAG, multi-tenancy, the
connector pattern, embeddings, OAuth, etc.), make sure that concept is represented in
`LEARNING.md` with the *why* behind the choice and a pointer to where it lives in the
code. The learning file should always describe the system that actually exists.
