# Phase 11 — Customer List & Segment Analytics Design Spec

**Date:** 2026-06-12
**Status:** Approved
**Scope:** Merchant-facing customer management endpoints (list, detail, conversation history) and segment analytics breaking down the customer base by profile attributes.
**Definition of done:** Merchants can browse their customer list, view an individual customer's profile and conversation history, and see an analytics breakdown of customers by skin type.

---

## 1. Gap analysis from Phase 10

| Gap | Impact |
|-----|--------|
| No way to list customers via API | Merchants can't browse or search their customer base; must query DB directly |
| No endpoint to see a customer's conversation history | Merchants can't investigate what a specific customer asked or received |
| No segment analytics on customer profiles | Merchants can't see how many customers have each skin type; can't target pack improvements |

---

## 2. Customer list & detail (P11-1)

### New CRUD in `customers.py`

```python
async def list_customers(
    session: AsyncSession,
    tenant_id: UUID,
    limit: int = 20,
    offset: int = 0,
) -> list[Customer]:
    result = await session.execute(
        select(Customer)
        .where(Customer.tenant_id == tenant_id)
        .order_by(Customer.created_at.desc())
        .limit(limit)
        .offset(offset)
    )
    return result.scalars().all()


async def count_customers(
    session: AsyncSession,
    tenant_id: UUID,
) -> int:
    result = await session.execute(
        select(func.count(Customer.id)).where(Customer.tenant_id == tenant_id)
    )
    return result.scalar_one()
```

### New router: `services/core/eshopeo/api/routers/customers.py`

Prefix: `/v1/customers`, tag: `customers`, auth: `get_tenant`

**`GET /v1/customers`** — paginated customer list

Query params: `limit: int = 20` (ge=1, le=100), `offset: int = 0` (ge=0)

Response:
```json
{
  "customers": [
    {
      "id": "uuid",
      "platform_id": "woo-123",
      "email_hash": "abc123",
      "profile": {"skin_type": "oily", "concerns": ["acne"]},
      "created_at": "2026-06-01T10:00:00+00:00"
    }
  ],
  "total": 42,
  "limit": 20,
  "offset": 0
}
```

**`GET /v1/customers/{customer_id}`** — single customer detail

Path param: `customer_id: UUID`

Response: same `CustomerOut` shape as list item (no `total`/`limit`/`offset`)

Returns 404 if not found.

### Register in `app.py`

```python
from eshopeo.api.routers import customers
app.include_router(customers.router)
```

### Tests — `test_customer_list.py` (3 tests)

1. `test_customer_list_returns_200` — mock `list_customers` + `count_customers`; assert 200 + `customers` length + `total`
2. `test_customer_detail_returns_200` — mock `get_customer_by_id` returning a customer; assert 200 + id matches
3. `test_customer_detail_404_on_unknown` — mock returns `None`; assert 404

---

## 3. Customer conversation history (P11-2)

### New CRUD in `conversations.py`

```python
async def list_conversations_by_customer(
    session: AsyncSession,
    tenant_id: UUID,
    customer_id: UUID,
    limit: int = 20,
    offset: int = 0,
) -> list[Conversation]:
    result = await session.execute(
        select(Conversation)
        .where(
            Conversation.tenant_id == tenant_id,
            Conversation.customer_id == customer_id,
        )
        .order_by(Conversation.created_at.desc())
        .limit(limit)
        .offset(offset)
    )
    return result.scalars().all()
```

### New endpoint in `customers.py` router

**`GET /v1/customers/{customer_id}/conversations`**

Path param: `customer_id: UUID`
Query params: `limit: int = 20` (ge=1, le=100), `offset: int = 0` (ge=0)

404 if the customer doesn't exist (call `get_customer_by_id` first).

Response:
```json
{
  "conversations": [
    {
      "id": "uuid",
      "customer_id": "uuid",
      "created_at": "2026-06-01T10:00:00+00:00",
      "updated_at": "2026-06-01T10:05:00+00:00"
    }
  ]
}
```

Uses `ConversationSummary` model (same shape as the one in `conversations.py` router — define locally in `customers.py`).

### Tests — `test_customer_conversations.py` (3 tests)

1. `test_customer_conversations_returns_200` — mock `get_customer_by_id` + `list_conversations_by_customer` returning 2 convs; assert 200 + list length
2. `test_customer_conversations_404_on_unknown_customer` — mock `get_customer_by_id` returns `None`; assert 404
3. `test_customer_conversations_requires_auth` — no `X-eShopeo-Tenant-Key`; assert 401

---

## 4. Customer segment analytics (P11-3)

### New CRUD in `customers.py` (CRUD file)

```python
async def get_customer_segments(
    session: AsyncSession,
    tenant_id: UUID,
) -> list[dict]:
    skin_type_col = func.jsonb_extract_path_text(
        Customer.profile, "skin_type"
    ).label("skin_type")
    result = await session.execute(
        select(skin_type_col, func.count(Customer.id).label("count"))
        .where(Customer.tenant_id == tenant_id)
        .group_by(skin_type_col)
        .order_by(func.count(Customer.id).desc())
    )
    return [
        {"skin_type": row.skin_type or "unknown", "count": row.count}
        for row in result.all()
    ]
```

`None` skin_type values (customers with no skin_type in profile) are bucketed as `"unknown"`.

### New endpoint in `analytics.py`

**`GET /v1/analytics/customers/segments`**

Auth: `get_tenant`

Import: `from eshopeo.db.crud.customers import get_customer_segments`

Response:
```json
{
  "segments": [
    {"skin_type": "oily", "count": 24},
    {"skin_type": "dry", "count": 18},
    {"skin_type": "unknown", "count": 5}
  ]
}
```

### Tests — `test_customer_segments.py` (3 tests)

1. `test_customer_segments_returns_200` — mock `get_customer_segments` returning 2 segments; assert 200 + segments length
2. `test_customer_segments_requires_auth` — 401
3. `test_customer_segments_empty` — mock returns `[]`; assert `segments: []`

---

## 5. File map

**New files:**
- `services/core/eshopeo/api/routers/customers.py` — `GET /v1/customers`, `GET /v1/customers/{id}`, `GET /v1/customers/{id}/conversations`
- `services/core/tests/test_customer_list.py` (3 tests)
- `services/core/tests/test_customer_conversations.py` (3 tests)
- `services/core/tests/test_customer_segments.py` (3 tests)

**Modified files:**
- `services/core/eshopeo/db/crud/customers.py` — add `list_customers`, `count_customers`, `get_customer_segments`
- `services/core/eshopeo/db/crud/conversations.py` — add `list_conversations_by_customer`
- `services/core/eshopeo/api/routers/analytics.py` — add `GET /v1/analytics/customers/segments`
- `services/core/eshopeo/api/app.py` — register `customers` router

---

## 6. Security constraints

- All CRUD queries scoped by `tenant_id` — no cross-tenant customer visibility
- `customer_id` path params validated as UUID — invalid → 422 (FastAPI handles)
- `email_hash` returned (not raw email) — PII-safe by design
- Segment analytics aggregates only — no individual profile data exposed
