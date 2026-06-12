# Helix — AI Commerce Intelligence Suite

Helix is a hosted, multi-tenant AI suite that plugs into **WooCommerce** and **Shopify**
stores to raise conversion and average order value. It adds an AI shopping consultant,
a routine/kit builder, semantic discovery, product-page intelligence, conversational
support, and retention automation — all driven from a central backend, with store-side
code kept deliberately thin.

The first vertical is **K-beauty**. All domain knowledge lives in a swappable *domain
pack*, so the same engine serves other verticals (e.g. automotive parts) by changing
data, not code.

> `helix` is a working codename. The public brand is configured at runtime — it is not
> hardcoded anywhere.

---

## Why it's built this way

The intelligence (LLM orchestration, customer profiles, embeddings, recommendation and
compatibility reasoning) runs in a **hosted, multi-tenant core**. Stores install a
**thin connector** that syncs catalog/customers/orders, injects the storefront widget,
and optionally writes content back. This shape is required to support Shopify (apps
cannot run arbitrary backend logic in-store) and is what lets one engine serve multiple
platforms and verticals. Full rationale: `docs/adr/0001-hosted-core-vs-self-contained-plugin.md`.

## Architecture at a glance

```
Storefront (Woo / Shopify theme)
   └── <helix-widget>  ──────────────►  Core API (FastAPI)  ──►  PostgreSQL + pgvector
                                              │                     Redis
Merchant store admin                          ├── LLM gateway ─► Anthropic Claude API
   └── Connector (PHP plugin / Remix app) ───►├── Domain-pack loader (kbeauty, …)
        • auth handshake                      └── Celery workers (sync, embed, generate)
        • catalog/customer/order sync
        • widget injection + write-back
```

- **Connector Contract** is the platform-agnostic boundary: both connectors translate
  their platform's data into canonical models; the core never branches on platform.
- **Domain pack** supplies the profile schema, product attributes, taxonomy,
  compatibility rules, and prompt fragments for a vertical. The core contains no
  vertical-specific literals.

See `docs/MASTER_PROMPT.md` for the complete specification, build phases, and rules.

## Repository layout

| Path | What lives here |
|------|-----------------|
| `services/core/` | Python package `helix` — FastAPI API and Celery worker share it |
| `services/dashboard/` | Merchant admin (React + Vite + Tailwind) |
| `services/widget/` | Embeddable storefront bundle (`<helix-widget>`, Preact) |
| `connectors/woocommerce/` | Thin WordPress plugin |
| `connectors/shopify/` | Thin Remix app |
| `packs/kbeauty/` | First domain pack (data, rules, prompt fragments) |
| `packs/motorspares/` | Second pack (added in Phase 5 to prove agnosticism) |
| `infra/` | Docker, `compose.yaml` |
| `docs/` | `MASTER_PROMPT.md`, `PROGRESS.md`, `LEARNING.md`, `adr/` |

## Tech stack

Python 3.12 · FastAPI · SQLAlchemy 2.0 · Pydantic v2 · PostgreSQL 16 + pgvector ·
Redis · Celery · Anthropic Claude API · Preact (widget) · React/Vite/Tailwind/shadcn
(dashboard) · PHP/WordPress (Woo connector) · Remix (Shopify connector) · Docker.

## Getting started (local development)

**Prerequisites:** Docker + Docker Compose, Python 3.12, Node 20+, and an Anthropic API
key.

```bash
git clone <repo-url> helix
cd helix
cp .env.example .env          # fill in ANTHROPIC_API_KEY and secrets
docker compose -f infra/compose.yaml up --build
```

This starts PostgreSQL (with pgvector), Redis, the core API, and a Celery worker.

```bash
# run migrations
docker compose -f infra/compose.yaml exec api alembic upgrade head

# run the test suite
docker compose -f infra/compose.yaml exec api pytest

# lint / format / type-check
docker compose -f infra/compose.yaml exec api ruff check . && black --check . && mypy .
```

Connecting a test store:

- **WooCommerce:** install the plugin in `connectors/woocommerce/` on a WordPress dev
  site, point it at your local API URL, and run the initial sync from the plugin
  settings.
- **Shopify:** run the Remix app in `connectors/shopify/` against a development store
  (added in Phase 4).

## Configuration

All configuration is via environment variables (see `.env.example`). Never commit
secrets. Key variables include the Anthropic API key, the pinned Claude model IDs per
tier, the database and Redis URLs, the credential-encryption key, and the public brand
name.

## Project status

Active early development. The current phase, task board, and decision log live in
`docs/PROGRESS.md` — that is the source of truth for what works today.

## Contributing

Read `docs/MASTER_PROMPT.md` first. Follow Conventional Commits, keep changes small and
idiomatic, write real tests, and update `docs/PROGRESS.md` with every meaningful change.
Code should read as if written by a careful human team — see the quality mandate in the
master prompt.

## License

_TBD._
