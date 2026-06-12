# Phase 7 — Streaming Widget Chat, Search Suggestions & Quota Visibility Design Spec

**Date:** 2026-06-12  
**Status:** Approved  
**Scope:** SSE streaming chat endpoint; product title autocomplete; per-tenant quota status API  
**Definition of done:** Widget embed can stream chat responses token-by-token; merchants can autocomplete product title searches; operators can check monthly quota usage via API.

---

## 1. Gap analysis from Phase 6

| Gap | Impact |
|-----|--------|
| Widget chat blocks until full response | AI chat UX feels slow; users see nothing for seconds |
| No title autocomplete on search | Merchants can't suggest product names to customers |
| No programmatic quota check | Tenants can't know how much of their monthly limit is left |

---

## 2. Streaming widget chat (P7-1)

`POST /v1/widget/chat/stream` — same auth as `/v1/widget/chat` (JWT bearer, `get_widget_tenant`), same request body (`ChatRequest`), returns `text/event-stream`.

### Architecture decision: full-response-as-stream (v1)

True token streaming requires deeply refactoring `LLMGateway.complete()` to expose Anthropic's streaming API. For v1, the endpoint runs the full `handle_query()` pipeline (unchanged), then yields the result as SSE events. This delivers the SSE protocol, lets the embed JS consume it incrementally, and defers true token streaming to a future phase.

### SSE event schema

Each event line: `data: {json}\n\n`

Event types:
- `{"type": "token", "content": "..."}` — the full response text in one event (v1 approach)
- `{"type": "done", "source": "template"|"rules"|"llm"}` — final event

### Implementation in `helix/api/routers/widget.py`

```python
import json
from fastapi.responses import StreamingResponse

@router.post("/chat/stream")
async def widget_chat_stream(
    body: ChatRequest,
    tenant: Tenant = Depends(get_widget_tenant),
    db: AsyncSession = Depends(get_db),
) -> StreamingResponse:
    settings = get_settings()
    pack = get_pack_for_tenant(tenant)

    # Profile merge (same as widget_chat)
    merged_profile = body.customer_profile
    if body.customer_id:
        try:
            cid = UUID(body.customer_id)
            customer = await get_customer_by_id(db, cid, tenant.id)
            if customer:
                merged_profile = {**(customer.profile or {}), **body.customer_profile}
        except ValueError:
            logger.warning("widget_chat_stream_invalid_customer_id", customer_id=body.customer_id)

    query_vector = await embed_query(body.query, settings)
    product_rows = await vector_search_products(db, tenant.id, query_vector, limit=5)
    context_products = [
        {
            "title": p.title,
            "price_minor": p.price_minor,
            "currency": p.currency,
            "categories": p.categories or [],
            "domain_attributes": p.domain_attributes or {},
        }
        for p, _ in product_rows
    ]

    result = await handle_query(
        query=body.query,
        customer_profile=merged_profile,
        context_products=context_products,
        tenant_id=tenant.id,
        pack=pack,
        settings=settings,
        db_session=db,
    )

    if result.cost_usd > 0:
        await create_usage_event(
            db, tenant.id, result.model,
            result.tokens_in, result.tokens_out,
            result.cost_usd, "/v1/widget/chat/stream",
        )
    await db.commit()

    async def _events():
        yield f"data: {json.dumps({'type': 'token', 'content': result.response})}\n\n"
        yield f"data: {json.dumps({'type': 'done', 'source': result.source})}\n\n"

    return StreamingResponse(_events(), media_type="text/event-stream")
```

---

## 3. Search product title suggestions (P7-2)

`GET /v1/search/suggest?q=ton&limit=5` — auth: `get_tenant`

Returns product titles that start with the given prefix (case-insensitive). Zero embeddings, zero LLM cost.

Response:
```json
{"suggestions": ["Toner A", "Toner Serum"], "prefix": "ton"}
```

### New CRUD function in `helix/db/crud/products.py`

```python
async def suggest_product_titles(
    session: AsyncSession,
    tenant_id: UUID,
    prefix: str,
    limit: int = 5,
) -> list[str]:
    result = await session.execute(
        select(Product.title)
        .where(
            Product.tenant_id == tenant_id,
            Product.title.ilike(f"{prefix}%"),
        )
        .order_by(Product.title)
        .limit(limit)
    )
    return [row.title for row in result]
```

### New endpoint in `helix/api/routers/search.py`

```python
class SuggestResponse(BaseModel):
    suggestions: list[str]
    prefix: str

@router.get("/suggest", response_model=SuggestResponse)
async def suggest_products(
    q: Annotated[str, Query(min_length=1)],
    limit: Annotated[int, Query(ge=1, le=20)] = 5,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> SuggestResponse:
    suggestions = await suggest_product_titles(db, tenant.id, q, limit)
    return SuggestResponse(suggestions=suggestions, prefix=q)
```

---

## 4. Quota status endpoint (P7-3)

`GET /v1/analytics/quota` — auth: `get_tenant`

Returns current-month quota consumption by reading the same Redis key the `QuotaMiddleware` writes.

Response:
```json
{
  "period": "2026-06",
  "used": 3421,
  "limit": 10000,
  "remaining": 6579
}
```

### New endpoint in `helix/api/routers/analytics.py`

```python
import redis.asyncio as aioredis
from datetime import datetime, timezone

class QuotaStatus(BaseModel):
    period: str
    used: int
    limit: int
    remaining: int

@router.get("/quota", response_model=QuotaStatus)
async def get_quota_status(
    tenant: Tenant = Depends(get_tenant),
) -> QuotaStatus:
    settings = get_settings()
    period = datetime.now(timezone.utc).strftime("%Y-%m")
    key = f"quota:{tenant.id}:{period}"
    redis_client = aioredis.from_url(str(settings.redis_url), decode_responses=True)
    try:
        used_str = await redis_client.get(key)
        used = int(used_str) if used_str else 0
    except Exception:
        used = 0
    finally:
        await redis_client.aclose()
    limit = settings.default_monthly_query_limit
    return QuotaStatus(
        period=period,
        used=used,
        limit=limit,
        remaining=max(0, limit - used),
    )
```

---

## 5. File map

**Modified files:**
- `services/core/helix/api/routers/widget.py` — add `POST /v1/widget/chat/stream`
- `services/core/helix/db/crud/products.py` — add `suggest_product_titles()`
- `services/core/helix/api/routers/search.py` — add `GET /v1/search/suggest`
- `services/core/helix/api/routers/analytics.py` — add `GET /v1/analytics/quota`

**New tests:**
- `services/core/tests/test_widget_chat_stream.py` (4 tests)
- `services/core/tests/test_search_suggest.py` (3 tests)
- `services/core/tests/test_quota_status.py` (3 tests)

---

## 6. Test plan

**test_widget_chat_stream.py:**
1. `test_chat_stream_returns_event_stream_content_type` — 200, `content-type: text/event-stream`
2. `test_chat_stream_contains_token_and_done_events` — response body contains both `"type": "token"` and `"type": "done"` SSE events
3. `test_chat_stream_passes_source_in_done_event` — mock `handle_query` returning `source="template"`, assert done event has `"source": "template"`
4. `test_chat_stream_requires_auth` — no Authorization header → 401

Parsing SSE in tests:
```python
lines = [l for l in r.text.split("\n") if l.startswith("data: ")]
events = [json.loads(l[6:]) for l in lines]  # strip "data: "
```

**test_search_suggest.py:**
1. `test_suggest_returns_matching_titles` — mock `suggest_product_titles` returning `["Toner A", "Toner B"]`, assert response has those titles
2. `test_suggest_empty_results_ok` — mock returns `[]`, assert 200 with empty suggestions
3. `test_suggest_requires_auth` — no `X-Helix-Tenant-Key` → 401

**test_quota_status.py:**
1. `test_quota_status_returns_used_count` — mock Redis `get` returning `"3421"`, assert response `used=3421, limit=10000, remaining=6579`
2. `test_quota_status_zero_when_key_missing` — mock Redis `get` returning `None`, assert `used=0, remaining=10000`
3. `test_quota_status_requires_auth` — no `X-Helix-Tenant-Key` → 401

---

## 7. Security constraints

- Streaming endpoint: same JWT auth as `/v1/widget/chat` — no new auth surface
- Quota endpoint: tenant-scoped Redis key — tenant can only see their own quota
- Search suggestions: `tenant_id` WHERE clause ensures cross-tenant isolation
- Quota key uses unverified claims from JWT (consistent with existing middleware)
