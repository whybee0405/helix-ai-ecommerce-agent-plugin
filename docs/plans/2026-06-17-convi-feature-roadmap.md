# Convi Feature Parity Roadmap

**Date:** 2026-06-17  
**Goal:** Close the gap between eShopeo and Convi (confiapp.com) across all 13 identified commerce features.  
**Platform scope:** WooCommerce first; Shopify in a later phase.

---

## Feature Status Baseline

| # | Feature | Status |
|---|---------|--------|
| 1 | Product Search | EXISTS — vector semantic search, full |
| 2 | Knowledge Search / FAQ | PARTIAL — TemplateLayer + pack FAQs; no admin FAQ CRUD |
| 3 | Lead Capture | MISSING |
| 4 | AI FAQ Widget on product pages | MISSING |
| 5 | Ask AI Button per product | PARTIAL — global float only; no per-product context |
| 6 | Inline Chat shortcode | PARTIAL — `[eshopeo_search]` exists; no full-chat shortcode |
| 7 | Cart Management (add/remove/qty) | PARTIAL — add-to-cart only; no remove/modify |
| 8 | Order Tracking | MISSING |
| 9 | Order Cancellation | MISSING |
| 10 | Edit Order Items | MISSING |
| 11 | Edit Shipping Address | MISSING |
| 12 | Handover to Human | MISSING |
| 13 | Web Search | MISSING |

---

## Sprint 1 — Quick Wins (< 1 day each)

### 3. Lead Capture

**Why it matters:** Every unanswered query that doesn't convert is a lost lead. Capture email/phone before the session ends.

**Backend (`services/core`)**
- Add `email`, `phone`, `opt_in_at` columns to `ChatRequest` (or a new `Lead` model with `tenant_id`, `session_id`, `email`, `phone`, `source_url`, `created_at`)
- `POST /v1/widget/capture-lead` — unauthenticated, takes `{public_key, session_id, email, phone?}`, validates, saves, fires tenant webhook if configured
- Rate-limit per IP to prevent abuse

**Plugin (`connectors/woocommerce`)**
- New WP option: `eshopeo_lead_capture_enabled` (checkbox in admin)
- Widget JS: after N turns (configurable, default 2) or on chat close, show inline opt-in form inside `#hx-chat-body`
- On submit, POST to `/v1/widget/capture-lead`; hide form, show "Thanks! We'll be in touch."

**Admin**
- Leads list page under eShopeo menu: table of leads with date, email, source URL, session link

---

### 8. Order Tracking

**Why it matters:** "Where is my order?" is the #1 post-purchase query. Deflecting it from human support is high-value.

**Backend**
- `GET /v1/widget/orders?email={email}&public_key={key}` — queries WC REST API with store credentials, returns sanitised order list: `{order_id, status, placed_at, items_summary, tracking_url?}`
- Intent detection: add `order_tracking` intent to the system prompt and dispatch logic
- Cache per (tenant, email) for 5 min in Redis

**Widget JS**
- When `intent == "order_tracking"` in API response, render a structured order card (status badge, items, tracking link) instead of plain text
- "Track my order" trigger phrase added to embed widget

---

### 5. Ask AI Button (per-product context)

**Why it matters:** Converts product page browsers into conversations with pre-loaded product context — higher intent than homepage search.

**Backend**
- `GET /v1/widget/product-context?platform_id={id}&public_key={key}` — returns full product context object (name, description, attributes, price, images) suitable for injecting as a pinned chat message
- Reuses existing `Product` model; no new DB work

**Plugin**
- New WP option: `eshopeo_ask_ai_button_enabled`
- PHP hook on `woocommerce_after_add_to_cart_button`: inject `<button class="hx-ask-ai-btn">Ask AI about this product</button>` with `data-product-id="{id}"`
- Widget JS: on button click, open embed widget and pre-seed the conversation with the product context fetched from `/v1/widget/product-context`

---

## Sprint 2 — Medium (1–3 days each)

### 7. Cart Management (Remove / Modify Qty)

**Current state:** Add-to-cart works via WC Store API. Remove and qty-change are unimplemented.

**Backend**
- Add `cart_remove` and `cart_update_qty` intents to dispatch logic
- Existing WC Store API endpoints handle the actual mutations — just need intent parsing + structured response telling the widget which Store API call to make client-side
- Response schema: `{action: "cart_remove"|"cart_update_qty", line_item_key: str, quantity?: int}`

**Widget JS**
- On receiving a cart mutation action, call WC Store API (`DELETE /wc/store/v1/cart/items/{key}` or `PUT /wc/store/v1/cart/items/{key}`) with nonce
- Refresh mini-cart after success; show confirmation message inline

---

### 12. Handover to Human

**Why it matters:** Critical for complex queries, complaints, and high-value customers. Convi has it; missing here is a trust gap.

**Backend**
- New `SupportTicket` model: `tenant_id`, `session_id`, `customer_email`, `transcript_json`, `status` (`open`/`resolved`), `created_at`
- `POST /v1/widget/escalate` — saves ticket, returns `{ticket_id, message}`
- Tenant webhook: POST to configured `eshopeo_escalation_webhook_url` with ticket payload (supports Zendesk, Freshdesk, plain email via SendGrid)
- New admin endpoint: `GET /v1/admin/tickets` — list open tickets with transcript

**Plugin**
- New WP option: `eshopeo_escalation_enabled`, `eshopeo_escalation_webhook_url`
- Widget JS: "Talk to a human" button in embed widget footer; confirm dialog → POST to `/v1/widget/escalate` → show ticket ID and "Someone will be in touch within {SLA}"

---

### 9. Order Cancellation

**Backend**
- Policy engine: tenant-configurable `cancellation_window_hours` (default 24), `cancellable_statuses` (default `["processing", "on-hold"]`)
- `POST /v1/widget/orders/{order_id}/cancel` — validates window + status, calls WC REST API to update status to `cancelled`, logs audit event to `OrderAudit` table (`tenant_id`, `order_id`, `action`, `actor`, `timestamp`, `reason`)
- Requires `customer_email` claim in session to authorise (no unauthenticated cancellations)

**Widget JS**
- After order tracking shows results, offer "Cancel this order" button if policy allows
- Confirm step: "Are you sure? This cannot be undone." → POST → show confirmation

---

### 2. FAQ Management CRUD (Admin)

**Backend**
- New `FAQ` model: `tenant_id`, `question`, `answer`, `embedding` (Vector 1024), `active`, `created_at`
- Alembic migration
- `POST/GET/PUT/DELETE /v1/admin/faqs` — full CRUD, auth via admin key
- On create/update: re-embed question+answer, store in `FAQ.embedding`
- Retrieval: FAQ embeddings searched alongside product embeddings in the main semantic search; top FAQ match injected into context if score > threshold

**Plugin**
- New admin page "FAQs" under eShopeo menu: datatable, add/edit/delete forms
- Calls the admin API endpoints via `wp_remote_post`

---

## Sprint 3 — Large (3+ days)

### 2b. AI FAQ Widget on Product Pages (Feature #4)

**Depends on:** FAQ Management CRUD (above)

**Backend**
- New `ProductFAQ` model: `tenant_id`, `product_platform_id`, `question`, `answer`, `generated_at`
- `POST /v1/admin/products/{id}/generate-faqs` — runs Claude to generate 5 common Q&As from product description + reviews + domain pack rules; stores in `ProductFAQ`
- `GET /v1/widget/product-faqs?platform_id={id}&public_key={key}` — returns active FAQs for product

**Plugin**
- New WP option: `eshopeo_product_faq_enabled`
- PHP hook `woocommerce_after_single_product_summary`: inject `<div id="hx-product-faqs" data-product-id="{id}"></div>`
- Widget JS (or new lightweight `product-faqs.js`): fetches and renders accordion FAQ below product description; "Ask AI" link on each FAQ opens embed with pre-seeded question

---

### 13. Web Search

**Backend**
- New `web_search` intent in dispatch logic
- Integration with Brave Search API (or Google Custom Search) — tenant API key stored encrypted in `Tenant.credentials_enc` extension
- Results cached in Redis with semantic deduplication (cosine similarity on query embedding)
- Source attribution included in response; no hallucination risk since answer is grounded in fetched content

**Widget JS**
- Web search results rendered as a separate card type with favicon, title, excerpt, URL
- Toggle in admin: `eshopeo_web_search_enabled`

---

### 10. Edit Order Items

**Backend**
- `PUT /v1/widget/orders/{order_id}/items` — payload: `{line_item_id, quantity}` or `{line_item_id, action: "remove"}`
- Validates order status (`processing` only), calls WC REST API `PUT /wc/v3/orders/{id}` to update line items
- Logs to `OrderAudit`; recalculates totals via WC

**Constraints:** WC only allows editing orders in `processing` status before fulfilment begins. Policy engine enforces this.

---

### 11. Edit Shipping Address

**Backend**
- New fields on `Order`-adjacent tracking: `fulfillment_status` (synced from WC on each tracking query)
- `PUT /v1/widget/orders/{order_id}/shipping` — payload mirrors WC shipping object; validates `fulfillment_status` == `unfulfilled`; calls WC REST API; logs to `OrderAudit`

**Widget JS**
- Inline address form (not a modal) rendered in chat after "Change my shipping address" intent

---

### 6b. Inline Chat Shortcode (full chat, not just search bar)

**Current state:** `[eshopeo_search]` renders the search-bar-only experience. Users want a full inline chat (messages, not just product search).

**Backend:** No changes — the embed widget already supports full chat.

**Plugin**
- New shortcode `[eshopeo_chat]` — renders a full-height inline chat container
- Widget JS: new `inlineChat` mode; renders into `#hx-chat-target` div instead of floating button
- Height configurable via shortcode attribute: `[eshopeo_chat height="600px"]`

---

## Sequencing Summary

```
Week 1:  Sprint 1 — Lead Capture + Order Tracking + Ask AI Button
Week 2:  Sprint 2 — Cart Remove/Modify + Handover to Human + Order Cancellation
Week 3:  Sprint 2 cont. — FAQ CRUD + Admin UI
Week 4+: Sprint 3 — AI FAQ Widget, Web Search, Edit Order/Shipping, Inline Chat
```

All features are additive — no existing functionality is modified. Each sprint ships independently and can be toggled per-tenant via the admin UI.
