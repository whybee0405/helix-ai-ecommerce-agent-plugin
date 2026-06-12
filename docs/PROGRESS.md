# Helix — Build Progress

## Status snapshot
- **Current phase:** Phase 11 — Customer List & Segment Analytics
- **Overall:** complete
- **Last updated:** 2026-06-12
- **Last worked by:** Claude Sonnet 4.6
- **Build health:** green — 202/202 tests pass

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

## Phase 1 — Intelligence Layer

All 6 tasks complete.

### Tasks
- [x] Task 1: Semantic product search (`GET /v1/search/products`, pgvector + Voyage AI)
- [x] Task 2: Customer sync endpoint (`POST /v1/sync/customers` with profile schema validation)
- [x] Task 3: Rule engine (compatibility check, routine ordering, missing steps)
- [x] Task 4: Redis LLM cache + gateway `route_query()` + intent classifier
- [x] Task 5: Widget chat endpoint (`POST /v1/widget/chat`, JWT auth, 4-layer routing)
- [x] Task 6: Routine builder (`POST /v1/widget/routine`, step-ordered, budget filter)

## Phase 2 — Hardening & Shopify

All 6 tasks complete.

### Tasks
- [x] Task 1: TemplateLayer keyword matching (Layer 3 FAQ, case-insensitive substring)
- [x] Task 2: Redis sliding-window rate limiting on widget endpoints (30 req/60s per tenant)
- [x] Task 3: Usage analytics endpoint (`GET /v1/analytics/usage` with date range + model breakdown)
- [x] Task 4: Shopify webhook router (`POST /v1/webhooks/shopify/products`, HMAC-SHA256)
- [x] Task 5: Shopify PHP connector plugin (4 files, mirrors WooCommerce pattern)
- [x] Task 6: Full test suite + PROGRESS.md

## Phase 4 — Production Hardening

All 6 tasks complete.

### Tasks
- [x] Task 1: CORS middleware + X-Request-Id correlation header
- [x] Task 2: Orders sync endpoint (`POST /v1/sync/orders`)
- [x] Task 3: Monthly quota middleware (Redis counter, 429 + X-Quota-Exceeded on limit)
- [x] Task 4: WooCommerce orders webhook (`POST /v1/webhooks/orders`)
- [x] Task 5: Dead code cleanup + Shopify webhook uses `get_pack_for_tenant`
- [x] Task 6: Full test suite + PROGRESS.md

## Phase 3 — Multi-Pack & Widget Embed

All 6 tasks complete.

### Tasks
- [x] Task 1: Tenant `pack_id` column (nullable) + Alembic migration 0002
- [x] Task 2: Per-tenant pack routing (`get_pack_for_tenant()`, replace all `default_pack()` callers)
- [x] Task 3: Tenant management endpoints (`GET /v1/tenants/{id}`, `PATCH /v1/tenants/{id}`)
- [x] Task 4: Job status endpoints (`GET /v1/jobs/{id}`, `GET /v1/jobs`)
- [x] Task 5: Widget JS embed (`GET /v1/widget/embed.js`, dev-only demo page)
- [x] Task 6: Full test suite + PROGRESS.md

## Phase 5: Shopify Orders, Admin Stats & Pack API ✅

All 5 tasks complete.

### Tasks
- [x] Task 1: Shopify orders webhook — `translate_shopify_order()` + `POST /v1/webhooks/shopify/orders` (4 tests)
- [x] Task 2: Admin platform stats — `get_platform_stats()` + `GET /v1/admin/stats` (3 tests)
- [x] Task 3: Pack listing API — `GET /v1/packs` + `GET /v1/packs/{id}` (4 tests)
- [x] Task 4: Search category filter — JSONB containment on Product.categories (3 tests)
- [x] Task 5: Full suite (133 tests) + PROGRESS.md

## Phase 6: Usage Event Persistence & Customer Profile Intelligence ✅

All 4 tasks complete.

### Tasks
- [x] Task 1: Usage event persistence — RouteResult cost fields + create_usage_event CRUD + widget endpoints write UsageEvent (7 tests)
- [x] Task 2: Customer profile in widget — get_customer_by_id CRUD + ChatRequest customer_id field + stored/request profile merge (4 tests)
- [x] Task 3: Customer profile update — get_customer_by_platform_id + update_customer_profile + PATCH /v1/sync/customers/{platform_id}/profile (3 tests)
- [x] Task 4: Full suite (147 tests) + PROGRESS.md update

## Phase 7: Streaming Widget Chat, Search Suggestions & Quota Visibility ✅

All 4 tasks complete.

### Tasks
- [x] Task 1: SSE streaming chat — `_run_chat_pipeline` helper + `POST /v1/widget/chat/stream` + `AsyncGenerator[str, None]` SSE events (4 tests)
- [x] Task 2: Product title autocomplete — `suggest_product_titles()` ILIKE prefix CRUD + `GET /v1/search/suggest` (3 tests)
- [x] Task 3: Quota status endpoint — `GET /v1/analytics/quota` reads Redis `quota:{tenant_id}:{YYYY-MM}` key (3 tests)
- [x] Task 4: Full suite (157 tests) + PROGRESS.md update

## Phase 8: Conversation History & Merchant Feedback ✅

All 4 tasks complete.

### Tasks
- [x] Task 1: Conversation models + CRUD — `Conversation` and `ConversationMessage` SQLAlchemy models, Alembic migration 0003, full CRUD layer (`create_conversation`, `append_messages`, `get_conversation`, `list_conversations`, `get_messages`, `get_message`, `set_message_feedback`) (5 tests)
- [x] Task 2: Widget conversation integration — `ChatRequest.conversation_id`, `ChatResponse.conversation_id` + `assistant_message_id`, `PipelineResult` dataclass, `_run_chat_pipeline` creates/appends Conversation rows, feedback endpoint `POST /v1/widget/conversations/{message_id}/feedback` (4 tests)
- [x] Task 3: Conversation list + detail endpoints — `GET /v1/conversations` (limit/offset, merchant auth), `GET /v1/conversations/{id}` with messages (4 tests)
- [x] Task 4: Message feedback tests — full coverage of `POST /v1/widget/conversations/{message_id}/feedback` (thumbs_up, thumbs_down, 404, 401) (4 tests)

## Phase 11: Customer List & Segment Analytics ✅

All 3 tasks complete.

### Tasks
- [x] Task 1: Customer list & detail — `list_customers` + `count_customers` CRUD + new `GET /v1/customers` (paginated) + `GET /v1/customers/{id}` router; registered in `app.py` (3 tests)
- [x] Task 2: Customer conversation history — `list_conversations_by_customer` CRUD + `GET /v1/customers/{id}/conversations` endpoint (route registered before `/{id}` to avoid FastAPI shadowing) (3 tests)
- [x] Task 3: Customer segment analytics — `get_customer_segments` CRUD (JSONB skin_type grouping, `None` → `"unknown"`) + `GET /v1/analytics/customers/segments` (3 tests)

## Phase 9: Conversation Context & Analytics ✅

All 3 tasks complete.

### Tasks
- [x] Task 1: Multi-turn context injection — restructured `_run_chat_pipeline` to resolve conversation before `handle_query`; `conversation_history` (last 10 messages) threaded through `handle_query` → `route_query` → `complete` and prepended to Anthropic `messages` array; template/rules layers unchanged (4 tests)
- [x] Task 2: Conversation analytics — `get_conversation_analytics` CRUD + `GET /v1/analytics/conversations` (total convs, messages, avg per conv, feedback positive rate) (3 tests)
- [x] Task 3: Top queries — `get_top_queries` CRUD + `GET /v1/analytics/top-queries` (most frequent customer queries grouped by content, descending count) (3 tests)

## Phase 10: Product Similarity & Performance Analytics ✅

All 3 tasks complete.

### Tasks
- [x] Task 1: Similar products — `get_similar_products` CRUD (pgvector cosine distance, excluding self) + `GET /v1/search/similar/{product_id}` (404 if no embedding) (3 tests)
- [x] Task 2: Top referenced products — `get_top_referenced_products` CRUD (unnest JSONB `products_referenced`, group by product_id) + `GET /v1/analytics/products/top` (3 tests)
- [x] Task 3: Embedding coverage — `get_embedding_coverage` CRUD (COUNT non-null embeddings) + `GET /v1/analytics/products/embedding-coverage` (coverage_rate=1.0 when no products) (3 tests)

## Session log

### 2026-06-12 (Phase 11) — Claude Sonnet 4.6
Built Phase 11 customer insights: new `GET /v1/customers` (paginated list with total count) and `GET /v1/customers/{id}` (detail) merchant endpoints via new `customers.py` router; `GET /v1/customers/{id}/conversations` endpoint for customer conversation history — route registered before `/{id}` to prevent FastAPI catch-all shadowing; `GET /v1/analytics/customers/segments` groups customers by `skin_type` JSONB attribute using `func.jsonb_extract_path_text`, buckets `None` as `"unknown"`. All endpoints tenant-scoped with `get_tenant` auth and 404 on unknown resource. 202 tests total (9 new Phase 11 + 193 prior). Next: Phase 12.

### 2026-06-12 (Phase 10) — Claude Sonnet 4.6
Built Phase 10 product intelligence and analytics: similar products endpoint (`GET /v1/search/similar/{product_id}`) uses pgvector cosine distance on existing embeddings, returns top N similar products for a tenant, 404 if source has no embedding; top referenced products (`GET /v1/analytics/products/top`) unnests the JSONB `products_referenced` array from assistant conversation messages, groups and counts by product ID; embedding coverage health check (`GET /v1/analytics/products/embedding-coverage`) uses `COUNT(embedding)` to count non-null rows, computes coverage rate (1.0 when catalog is empty). All endpoints merchant-facing with `get_tenant` auth and tenant isolation. 193 tests total (9 new Phase 10 + 184 prior). Next: Phase 11.

### 2026-06-12 (Phase 9) — Claude Sonnet 4.6
Built Phase 9 conversation context and analytics: restructured `_run_chat_pipeline` to resolve the conversation before calling `handle_query`, fetch prior messages (last 10), and pass `conversation_history` through `handle_query` → `route_query` → `complete` where they are prepended to the Anthropic `messages` array — enabling genuine multi-turn follow-up questions. Template and rules layers remain stateless. Mutable default arg anti-pattern fixed (`None` sentinel). Two new analytics endpoints: `GET /v1/analytics/conversations` (volume, avg messages, feedback rate) and `GET /v1/analytics/top-queries` (most frequent user queries, grouped exact match). 184 tests total (10 new Phase 9 + 174 prior). Next: Phase 10.

### 2026-06-12 (Phase 8) — Claude Sonnet 4.6
Built Phase 8 conversation history and merchant feedback: persisted every widget chat turn as paired `Conversation` + `ConversationMessage` rows (user + assistant) in PostgreSQL; `_run_chat_pipeline` now creates or appends to a Conversation and returns `PipelineResult(route, conversation_id, assistant_message_id)`; `ChatResponse` includes both IDs so the embed JS can link feedback to specific messages. Merchant-facing read endpoints (`GET /v1/conversations`, `GET /v1/conversations/{id}`) with `get_tenant` auth and tenant isolation. Customer-initiated feedback endpoint (`POST /v1/widget/conversations/{message_id}/feedback`) using widget JWT — role check (`role == "assistant"`) enforced inside `set_message_feedback` CRUD, endpoint makes a single call (no double-fetch). 174 tests total (17 new Phase 8 + 157 prior). Next: Phase 9.

### 2026-06-12 (Phase 7) — Claude Sonnet 4.6
Built Phase 7 streaming and observability: SSE streaming chat endpoint (`POST /v1/widget/chat/stream`) using `_run_chat_pipeline` helper that eliminates the duplicated embed→search→handle_query→usage pipeline from `widget_chat`; streaming yields two events: `{"type":"token","content":"..."}` then `{"type":"done","source":"..."}`. Product title autocomplete (`GET /v1/search/suggest`) with ILIKE prefix match, tenant-scoped, alphabetically ordered. Quota status endpoint (`GET /v1/analytics/quota`) reads Redis `quota:{tenant_id}:{YYYY-MM}` key written by QuotaMiddleware, falls back to 0 with structlog warning on Redis error, test assertions use `settings.default_monthly_query_limit` not magic number. 157 tests total (10 new Phase 7 + 147 prior). Next: Phase 8.

### 2026-06-11 (Phase 6) — Claude Sonnet 4.6
Built Phase 6 usage persistence and customer intelligence: extended RouteResult with cost metadata (model, tokens_in, tokens_out, cost_usd) accumulated in _log_usage (classify reset before generate to avoid bleed); create_usage_event CRUD writes UsageEvent rows after widget LLM calls (analytics endpoint now returns real data); widget_chat optionally merges stored Customer.profile with request profile when customer_id UUID provided (request keys win); PATCH /v1/sync/customers/{platform_id}/profile adds profile update with merge semantics. 147 tests total (14 new Phase 6 + 133 prior). Next: Phase 7.

### 2026-06-11 (Phase 5) — Claude Sonnet 4.6
Built Phase 5 Shopify/admin/pack API: Shopify orders webhook (`POST /v1/webhooks/shopify/orders`) with `translate_shopify_order()` and customer_id resolution; admin platform stats (`GET /v1/admin/stats`, auth: provision key, cross-tenant COUNTs + usage SUM); pack listing API (`GET /v1/packs`, `GET /v1/packs/{id}`, reads in-memory registry); search category filter (JSONB `@>` containment on Product.categories). 133 tests total (14 new Phase 5 + 119 prior). Next: Phase 6.

### 2026-06-11 (Phase 4) — Claude Sonnet 4.6
Built Phase 4 production hardening: CORS middleware with configurable origins exposing X-Request-Id; request correlation ID middleware (echoes or generates UUID per request); orders sync endpoint (`POST /v1/sync/orders`) completing the product+customer+orders data loop; monthly quota middleware (Redis INCR per `quota:{tenant_id}:{YYYY-MM}`, 32-day TTL, 429 + X-Quota-Exceeded header, fails open); WooCommerce orders webhook (`POST /v1/webhooks/orders`); dead code removal (pack variable in WC product webhook) and Shopify webhook now uses `get_pack_for_tenant(tenant)`. 119 tests total (20 new Phase 4 + 99 prior). Next: Phase 5 — Shopify orders webhook, admin stats, billing events.

### 2026-06-11 (Phase 3) — Claude Sonnet 4.6
Built Phase 3 multi-pack and widget: per-tenant `pack_id` column with migration 0002; `get_pack_for_tenant()` replacing `default_pack()` in all widget/sync callers; tenant management endpoints (GET + PATCH, auth: provision key); job status endpoints scoped by tenant (GET single + list with type filter); embeddable vanilla-JS chat widget served from `/v1/widget/embed.js` with floating UI and localStorage token caching; dev-only demo page. 99 tests total (18 new Phase 3 + 81 prior). Next: Phase 4 — billing metering, multi-tenant admin dashboard, production hardening.

### 2026-06-11 (Phase 2) — Claude Sonnet 4.6
Built Phase 2 hardening and Shopify connector: completed TemplateLayer with case-insensitive keyword matching; Redis sliding-window rate limiter middleware (30 req/60s per tenant, fails open on error); usage analytics endpoint querying `usage_event` with GROUP BY model and date range filtering; Shopify HMAC-SHA256 webhook verification + product webhook router mirroring WooCommerce pattern; Shopify PHP plugin (4 files: main plugin, API client, sync, webhooks). 81 tests total (18 new Phase 2 + 63 prior). Next: Phase 3 — multi-pack support, tenant onboarding flow, widget JS embed.

### 2026-06-11 (Phase 1) — Claude Sonnet 4.6
Built Phase 1 intelligence layer: semantic search (pgvector via Voyage AI `voyage-3-lite`), customer sync with pack profile schema validation, rule engine (ingredient compatibility + routine step ordering), Redis LLM cache keyed on sha256, LLM gateway `route_query()` with intent classifier (Haiku) and 4-layer routing (template → rules → LLM), widget chat endpoint with JWT auth, consultant domain logic, routine builder with budget filtering. 63 tests total (23 new Phase 1 + 40 Phase 0). Next: Phase 2 — rate limiting, analytics, Shopify connector.

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
