# Helix — Build Progress

## Status snapshot
- **Current phase:** Phase 0 — Foundations
- **Overall:** complete
- **Last updated:** 2026-06-11
- **Last worked by:** Claude Sonnet 4.6
- **Build health:** green — all tests pass, health endpoint returns ok

## Phase 0 — Foundations

All 19 tasks complete.

### Tasks
- [x] Task 1: Monorepo scaffold
- [x] Task 2: Docker infrastructure
- [x] Task 3: Application config
- [x] Task 4: Database models
- [x] Task 5: Database engine + Alembic setup
- [x] Task 6: Initial database migration
- [x] Task 7: Tenant scope + CRUD layer
- [x] Task 8: FastAPI app skeleton + health endpoint
- [x] Task 9: Credential encryption + auth tokens
- [x] Task 10: Canonical connector models
- [x] Task 11: Domain-pack loader + kbeauty seed
- [x] Task 12: LLM gateway
- [x] Task 13: Provisioning endpoint
- [x] Task 14: Sync endpoint
- [x] Task 15: Webhook endpoint + widget session
- [x] Task 16: Embedding pipeline
- [x] Task 17: WooCommerce PHP plugin
- [x] Task 18: ADRs
- [x] Task 19: Full test suite + PROGRESS.md

## Session log

### 2026-06-11 — Claude Sonnet 4.6
Built all 19 Phase 0 tasks end-to-end: monorepo scaffold, Docker infra, SQLAlchemy models + Alembic migration, tenancy + auth (Fernet + JWT), LLM gateway with layered routing, domain-pack loader, kbeauty seed, connector contract (CanonicalProduct/Customer/Order), provisioning + sync + webhook + widget session endpoints, Voyage AI embedding pipeline, WooCommerce PHP plugin, and 5 ADRs. All Python tests pass (40 total); health endpoint wired. Notable: engine.py refactored to lazy-initialize to avoid module-level settings calls in tests; conftest seeds stable test settings. Next: Phase 1 — semantic search, AI consultant, routine builder.

## Architecture decisions

| Date | Decision | ADR |
|------|----------|-----|
| 2026-06-11 | Hosted multi-tenant core vs self-contained plugin | 0001 |
| 2026-06-11 | PostgreSQL + pgvector single datastore | 0002 |
| 2026-06-11 | Domain pack as declarative data | 0003 |
| 2026-06-11 | Voyage AI voyage-3-lite for product embeddings | 0004 |
| 2026-06-11 | LLM gateway layered routing (vector → rules → templates → LLM) | 0005 |
