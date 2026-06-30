# eShopeo — AI Commerce Intelligence

eShopeo is a hosted backend that plugs into **WooCommerce** and **Shopify** stores to add an AI shopping consultant, semantic product search, a skincare routine builder, and AI-generated product descriptions — all from a single service, shared across stores.

The first vertical is **K-beauty**. Domain knowledge (product attributes, compatibility rules, prompt tone, routine structure) lives in a swappable *domain pack*, so the same engine can serve other verticals without touching core code.

---

## How it works

The store installs a thin connector plugin (PHP for WooCommerce, a Remix app for Shopify). The connector syncs the catalog, customer profiles, and orders to eShopeo, then injects a lightweight chat widget into the storefront. All the heavy work — embeddings, LLM calls, conversation history, content generation — runs on the eShopeo backend.

```
Storefront theme
  └── widget JS  ─────────────────────►  eShopeo API (FastAPI)
                                              │
Merchant admin                                ├── PostgreSQL + pgvector
  └── Connector plugin                        ├── Redis (cache / queues)
       • catalog sync                         ├── Claude API (Anthropic)
       • customer / order sync                ├── Voyage AI (embeddings)
       • widget injection                     └── Celery workers
```

**Connector Contract:** both connectors translate their platform's data into the same canonical models — the core never branches on platform type. Adding a third platform means writing a new connector, not touching the engine.

**Domain pack:** a YAML directory that supplies the customer profile schema, product attribute schema, routine step order, compatibility rules, and prompt fragments for a vertical. The core contains no vertical-specific strings.

---

## Stack

- **Backend:** Python 3.12, FastAPI, SQLAlchemy 2.0, Pydantic v2, Alembic
- **Database:** PostgreSQL 16 with the pgvector extension
- **Cache / queues:** Redis, Celery
- **AI:** Anthropic Claude (Haiku for classification, Sonnet for generation), Voyage AI for embeddings
- **Connectors:** PHP / WordPress (WooCommerce), Remix (Shopify)
- **Infrastructure:** Docker, Docker Compose

---

## Repository layout

```
services/core/       Python package — FastAPI API and Celery worker
connectors/
  woocommerce/       WordPress plugin (PHP)
  shopify/           Shopify connector (Remix)
packs/
  kbeauty/           K-beauty domain pack (YAML + rules)
infra/
  compose.yaml       Docker Compose for local dev
docs/
  adr/               Architecture Decision Records
  PROGRESS.md        What's built and what's next
```

---

## Running locally

**Prerequisites:** Docker + Docker Compose, Python 3.12, an Anthropic API key, a Voyage AI API key.

```bash
git clone https://github.com/whybee0405/eshopeo-ai-ecommerce-agent-plugin.git eshopeo
cd eshopeo
cp .env.example .env   # fill in API keys and secrets
docker compose -f infra/compose.yaml up --build
```

Apply migrations and run the test suite:

```bash
docker compose -f infra/compose.yaml exec api alembic upgrade head
docker compose -f infra/compose.yaml exec api pytest
```

See `.env.example` for all required variables. Never commit the `.env` file.

---

## What's working

- Semantic product search (pgvector + Voyage AI embeddings)
- AI shopping consultant with multi-turn conversation history
- Skincare routine builder with ingredient compatibility checking
- AI product description generation with a merchant draft/approve workflow
- WooCommerce and Shopify connectors with webhook support
- Per-tenant quota enforcement and usage analytics
- Merchant dashboard, customer segments, order analytics
- Embeddable storefront widget served from the API

See `docs/PROGRESS.md` for current state and known limitations.

---

## License

MIT
