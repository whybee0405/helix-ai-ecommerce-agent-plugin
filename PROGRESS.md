# PROGRESS.md — eShopeo Commerce Intelligence

> **This file is the running state of the project. Read it in full at the start of every
> session, before doing anything. Update it as you work — when you start a task, finish
> one, make a decision, or hit a blocker — and never end a session without leaving it
> accurate. Continuity across sessions depends entirely on this file.**

---

## How to use this file (protocol)

1. On session start: read the **Status snapshot**, **Current phase**, and **Last session
   summary**. That tells you exactly where things stand.
2. Pick the next `TODO` task in the current phase (top to bottom) unless directed
   otherwise. Move it to `IN PROGRESS` and add your name/agent + date.
3. As you complete work, move tasks to `DONE` with the date and the commit hash(es).
4. Record any decision in the **Decisions log** (and a full ADR in `docs/adr/`).
5. Record anything stopping you in **Blockers**.
6. On session end: write a 3–6 line entry under **Session log** describing what changed,
   why, and what the next session should pick up. Update the **Status snapshot** numbers.

Task states: `TODO` → `IN PROGRESS` → `BLOCKED` → `DONE`.

---

## Status snapshot

- **Current phase:** Phase 0 — Foundations
- **Overall:** not started
- **Last updated:** _(set on first edit)_
- **Last worked by:** _(agent/human + date)_
- **Build health:** _(green / yellow / red — does it run? do tests pass?)_

---

## Current phase: Phase 0 — Foundations

**Goal / definition of done:** a real WooCommerce store's catalog syncs into PostgreSQL
with embeddings generated, via the connector contract, with tenancy and the LLM gateway
in place.

### Tasks

- [ ] `TODO` Scaffold the monorepo per MASTER_PROMPT §4 (folders, `pyproject.toml`,
      tooling: Ruff, Black, mypy, pytest).
- [ ] `TODO` `infra/compose.yaml`: Postgres 16 + pgvector, Redis, the core API, a Celery
      worker. One command brings the dev stack up.
- [ ] `TODO` Core API skeleton (FastAPI), health check, settings via `config.py`,
      structured JSON logging.
- [ ] `TODO` DB layer: SQLAlchemy 2.0 models + Alembic; initial migration for `tenant`,
      `product`, `customer`, `order`, `job`, `usage_event`; enable `pgvector`.
- [ ] `TODO` Tenancy + auth: tenant provisioning, encrypted credential storage,
      tenant-scoped query enforcement at the data layer.
- [ ] `TODO` LLM gateway (`eshopeo.llm`): tiered model selection, prompt assembly,
      structured-output parse+validate+repair, retries, per-tenant usage metering.
- [ ] `TODO` Domain-pack loader (`eshopeo.packs`): load a pack, validate its schemas, expose
      profile/product schema + prompt fragments to the rest of the app.
- [ ] `TODO` Connector contract (`eshopeo.connectors`): canonical models, the `/v1` sync +
      webhook endpoints, signature verification.
- [ ] `TODO` WooCommerce connector (thin PHP plugin): install handshake, full catalog
      sync, product webhooks, translate to `CanonicalProduct`.
- [ ] `TODO` Embedding pipeline: on product upsert, enqueue a Celery task to embed and
      store the vector in pgvector.
- [ ] `TODO` Seed the `kbeauty` pack with a minimal `profile_schema` + `product_schema`
      so sync validates real attributes.
- [ ] `TODO` ADR `0001` (hosted core) written; ADRs for DB choice and pack format.

### Phase 0 exit check

- [ ] A live Woo store installs the plugin, syncs its catalog, and products appear in
      Postgres with embeddings. Tests + lint + types green. README setup steps verified
      from a clean clone.

---

## Upcoming phases (high level — break into tasks when the phase begins)

- **Phase 1 — Conversion core:** semantic search, AI Consultant, Routine/Kit Builder,
  PDP "will this work for me," widget rendering these on a live store.
- **Phase 2 — Content + support:** bulk description/SEO generation + write-back, review
  synthesis, grounded support agent, on-page Q&A.
- **Phase 3 — Retention:** abandonment recovery, replenishment, lifecycle messaging,
  WhatsApp channel.
- **Phase 4 — Shopify parity + commercialization:** Shopify connector, merchant
  dashboard, billing (PayFast + Shopify), analytics digest.
- **Phase 5 — Prove agnosticism:** author the `motorspares` pack and stand up a second
  store with zero core changes.

---

## Decisions log

> One line per decision; link the full ADR. Newest at the top.

| Date | Decision | ADR |
|------|----------|-----|
| _pending_ | Hosted multi-tenant core + thin connectors (not a self-contained plugin) | `0001` |

---

## Blockers

> Anything stopping progress: missing credentials, an undecided question, a failing
> dependency. Owner + date. Clear them as they resolve.

- _(none yet)_

---

## Open questions for the human

> Things an agent should not decide alone — pricing tiers, brand name, legal/PoPIA,
> which Claude model tier per feature once cost data exists.

- Public brand name (codename `eshopeo` is a placeholder).
- Confirm target host (Hetzner ZA region) and managed-Postgres vs self-hosted.

---

## Session log

> Newest entry at the top. 3–6 lines each: what changed, why, what's next.

- _(no sessions yet — first agent: start with the monorepo scaffold task and write the
  first entry here.)_
