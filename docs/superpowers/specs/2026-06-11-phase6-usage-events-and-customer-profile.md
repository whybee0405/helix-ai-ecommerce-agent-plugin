# Phase 6 — Usage Event Persistence & Customer Profile Intelligence Design Spec

**Date:** 2026-06-11  
**Status:** Approved  
**Scope:** Persist LLM usage events to database; personalize widget with stored customer profiles; add customer profile update endpoint  
**Definition of done:** Analytics endpoint returns real data (not zeros); widget chat uses stored customer profiles when a customer_id is provided; operators can update customer profiles via API.

---

## 1. Gap analysis from Phase 5

| Gap | Impact |
|-----|--------|
| `LLMGateway._log_usage()` only writes to structlog — zero UsageEvent rows ever written | `GET /v1/analytics/usage` always returns zeros; admin stats `cost_this_month_usd` always 0 |
| Widget chat ignores stored customer profiles | Recommendations are never personalized; Customer.profile data is orphaned |
| No endpoint to update a customer's skin profile | Operators can sync customers (Phase 0) but cannot update profiles after sync |

---

## 2. Usage event persistence (P6-1)

The fix requires the least disruption: route_query already tracks which LLM model was called and can compute cost. We add cost metadata to `RouteResult`, then write one `UsageEvent` per chat/routine request in the widget endpoint — after the call succeeds, before the commit.

### 2a. Extend `RouteResult` in `helix/llm/gateway.py`

Add fields to `RouteResult`:
```python
class RouteResult:
    def __init__(
        self,
        response: str,
        source: str,
        products_referenced: list[str] | None = None,
        model: str = "",
        tokens_in: int = 0,
        tokens_out: int = 0,
        cost_usd: float = 0.0,
    ) -> None:
        ...
```

Populate in `route_query` when the LLM path is actually taken:
- When `complete()` is called for the GENERATE tier, capture `message.usage.input_tokens`, `message.usage.output_tokens`, compute cost via `_COSTS[model_id]`, set on the returned `RouteResult`.
- Template and rule-engine paths return `cost_usd=0.0, model="", tokens_in=0, tokens_out=0`.

The `_log_usage` method currently logs to structlog — leave it unchanged (dual signal: log + DB).

### 2b. New CRUD: `helix/db/crud/usage_events.py`

```python
async def create_usage_event(
    session: AsyncSession,
    tenant_id: UUID,
    model: str,
    tokens_in: int,
    tokens_out: int,
    cost_usd: float,
    endpoint: str,
) -> UsageEvent:
    event = UsageEvent(
        tenant_id=tenant_id,
        model=model,
        tokens_in=tokens_in,
        tokens_out=tokens_out,
        cost_usd=cost_usd,
        endpoint=endpoint,
    )
    session.add(event)
    await session.flush()
    return event
```

### 2c. Widget chat writes usage event

In `helix/api/routers/widget.py`, after `handle_query` returns:
```python
result = await handle_query(...)
if result.cost_usd > 0:
    await create_usage_event(
        db, tenant.id, result.model,
        result.tokens_in, result.tokens_out,
        result.cost_usd, "/v1/widget/chat",
    )
await db.commit()
```

Same pattern for `widget_routine` with endpoint `"/v1/widget/routine"`.

Note: `widget_chat` currently has no `db.commit()` — one must be added. The db session is already in scope via `Depends(get_db)`.

---

## 3. Customer profile in widget chat (P6-2)

Widget sessions are anonymous today. Merchants can identify their customers by passing a `customer_id` (the Helix UUID of the customer, obtained after sync). When provided, the stored `Customer.profile` is merged with the request's `customer_profile`.

### 3a. `ChatRequest` model change

```python
class ChatRequest(BaseModel):
    query: str
    customer_profile: dict = {}
    customer_id: str | None = None  # Helix customer UUID (optional)
```

### 3b. Profile merge in `widget_chat`

```python
merged_profile = body.customer_profile
if body.customer_id:
    try:
        cid = UUID(body.customer_id)
        customer = await get_customer_by_id(db, cid, tenant.id)
        if customer:
            merged_profile = {**customer.profile, **body.customer_profile}
    except ValueError:
        pass  # invalid UUID — ignore
```

Pass `merged_profile` to `handle_query` instead of `body.customer_profile`.

### 3c. New CRUD function in `helix/db/crud/customers.py`

```python
async def get_customer_by_id(
    session: AsyncSession,
    customer_id: UUID,
    tenant_id: UUID,
) -> Customer | None:
    result = await session.execute(
        select(Customer).where(
            Customer.id == customer_id,
            Customer.tenant_id == tenant_id,
        )
    )
    return result.scalar_one_or_none()
```

Tenant scoping: `Customer.tenant_id == tenant_id` ensures cross-tenant isolation.

---

## 4. Customer profile update endpoint (P6-3)

Operators can update a customer's skin profile after sync (e.g. after the customer fills in a questionnaire on the storefront). Profile is merged — existing keys are overwritten, new keys are added.

### New endpoint in `helix/api/routers/sync.py`

`PATCH /v1/sync/customers/{platform_id}/profile`

Auth: `get_tenant` dep (same as other sync endpoints)

Request body:
```json
{"profile": {"skin_type": "combination", "skin_concerns": ["acne", "dark spots"]}}
```

Response: `{"customer_id": "<uuid>", "platform_id": "<str>"}`

Logic:
1. Look up `Customer` by `(tenant_id, platform_id)`
2. If not found → 404
3. Merge incoming `profile` dict into `customer.profile`: `new_profile = {**customer.profile, **body.profile}`
4. Call `update_customer_profile(session, customer, new_profile)` CRUD function
5. `await db.commit()`

### New CRUD function in `helix/db/crud/customers.py`

```python
async def update_customer_profile(
    session: AsyncSession,
    customer: Customer,
    new_profile: dict,
) -> Customer:
    customer.profile = new_profile
    session.add(customer)
    await session.flush()
    await session.refresh(customer)
    return customer
```

Add `get_customer_by_platform_id_and_tenant` to `helix/db/crud/customers.py` (check if it already exists, just look up by platform_id + tenant_id):
```python
async def get_customer_by_platform_id(
    session: AsyncSession,
    tenant_id: UUID,
    platform_id: str,
) -> Customer | None:
    result = await session.execute(
        select(Customer).where(
            Customer.tenant_id == tenant_id,
            Customer.platform_id == platform_id,
        )
    )
    return result.scalar_one_or_none()
```

(Check orders.py — `get_customer_id_by_platform_id` in `helix/db/crud/orders.py` does something similar but returns UUID. We need the full Customer object here.)

---

## 5. File map

**New files:**
- `services/core/helix/db/crud/usage_events.py`

**Modified files:**
- `services/core/helix/llm/gateway.py` — extend `RouteResult` with cost fields, populate in `route_query`
- `services/core/helix/api/routers/widget.py` — write UsageEvent after LLM calls; add `customer_id` to `ChatRequest`; merge stored profile
- `services/core/helix/db/crud/customers.py` — add `get_customer_by_id` and `update_customer_profile` (check existing file for `get_customer_by_platform_id`)
- `services/core/helix/api/routers/sync.py` — add `PATCH /v1/sync/customers/{platform_id}/profile`

**New tests:**
- `services/core/tests/test_usage_event_persistence.py` (4 tests)
- `services/core/tests/test_widget_customer_profile.py` (4 tests)
- `services/core/tests/test_customer_profile_update.py` (3 tests)

---

## 6. Test plan

**test_usage_event_persistence.py:**
1. `test_widget_chat_creates_usage_event_when_llm_called` — mock `handle_query` returning `RouteResult(cost_usd=0.05, model="claude-sonnet-4-6", tokens_in=100, tokens_out=50, ...)`, assert `create_usage_event` called with those values
2. `test_widget_chat_skips_usage_event_when_cached` — mock `handle_query` returning `RouteResult(cost_usd=0.0, source="template")`, assert `create_usage_event` NOT called
3. `test_widget_routine_creates_usage_event` — same as test 1 but for `/v1/widget/routine`
4. `test_create_usage_event_crud` — unit test: construct UsageEvent via CRUD, assert all fields set

**test_widget_customer_profile.py:**
1. `test_chat_uses_stored_profile_when_customer_id_provided` — mock `get_customer_by_id` returning customer with `profile={"skin_type": "oily"}`, request sends `customer_profile={"skin_concerns": ["acne"]}`, assert merged profile passed to `handle_query`
2. `test_request_profile_overrides_stored_profile` — same but request sends `{"skin_type": "dry"}` → merged has `skin_type="dry"` (request wins)
3. `test_chat_works_without_customer_id` — no `customer_id` in request → `get_customer_by_id` not called, request's `customer_profile` used as-is
4. `test_chat_ignores_invalid_customer_id` — `customer_id="not-a-uuid"` → no crash, falls back to request profile

**test_customer_profile_update.py:**
1. `test_patch_profile_merges_with_existing` — customer has `profile={"skin_type": "oily"}`, PATCH sends `{"skin_type": "dry", "concerns": ["acne"]}` → stored profile becomes `{"skin_type": "dry", "concerns": ["acne"]}`
2. `test_patch_profile_404_unknown_customer` — unknown `platform_id` → 404
3. `test_patch_profile_requires_auth` — no `X-Helix-Tenant-Key` → 401

---

## 7. Security constraints

- Customer profile lookup in widget: scoped by `tenant_id` from the authenticated JWT — a customer from tenant A is invisible to tenant B even if their UUID is known
- Profile update endpoint: auth via `X-Helix-Tenant-Key` (same as all sync endpoints)
- Usage events: tenant_id comes from authenticated tenant object, never from request body
- `customer_id` in ChatRequest is treated as untrusted input — invalid UUID silently ignored, and the DB lookup is tenant-scoped

---

## 8. Cost impact

- Usage event writes: one INSERT per LLM call (only when `cost_usd > 0`) — negligible DB cost vs LLM cost
- Profile lookup: one SELECT per widget chat request when `customer_id` provided — negligible
- No new LLM calls introduced
