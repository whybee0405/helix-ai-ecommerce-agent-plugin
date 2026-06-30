# Phase 3 — Multi-Pack & Widget Embed Design Spec

**Date:** 2026-06-11  
**Status:** Approved  
**Scope:** Per-tenant domain packs, tenant management API, job status visibility, embeddable widget JS  
**Definition of done:** Tenants can be assigned any loaded pack; operators can read/update tenants via API; sync jobs have status endpoints; a store owner can drop a single `<script>` tag to embed the AI chat widget.

---

## 1. Gap analysis from Phase 2

Four issues remain before eShopeo is operator-ready:

| Gap | Impact |
|-----|--------|
| `default_pack()` always returns the first pack regardless of tenant | K-beauty only; can't onboard a non-beauty vertical |
| No way to read or update tenant config post-provisioning | Operators need direct DB access to change anything |
| Job model exists but no status endpoint | Operators can't tell if a sync succeeded or is stuck |
| Widget chat exists as an API but has no storefront-side embed | Store owners need to integrate via code; widget isn't shippable |

---

## 2. Per-tenant domain packs (P3-1, P3-2)

### 2a. Database change

Add `pack_id: str` to the `Tenant` table, nullable with no default (resolved to `"kbeauty"` at application layer so existing rows keep working without a backfill migration).

New Alembic migration `0002_tenant_pack_id.py`:
```sql
ALTER TABLE tenant ADD COLUMN pack_id VARCHAR;
```

### 2b. Provisioning update

`POST /v1/tenants` accepts optional `pack_id: str = "kbeauty"` in the request body. Stored on the new column.

### 2c. Pack routing

New function in `eshopeo/packs/registry.py`:

```python
def get_pack_for_tenant(tenant: Tenant) -> LoadedPack:
    pack_id = tenant.pack_id or "kbeauty"
    if pack_id in _registry:
        return _registry[pack_id]
    return default_pack()  # fallback: first loaded pack
```

All four callers of `default_pack()` in `widget.py` (×2) and `search.py` and `sync.py` are updated to call `get_pack_for_tenant(tenant)` instead.

---

## 3. Tenant management endpoints (P3-3)

Auth: `X-eShopeo-Provision-Key` header (same as provisioning).

### `GET /v1/tenants/{tenant_id}`

Returns tenant details. No credentials in response.

```json
{
  "tenant_id": "...",
  "name": "Seoul Beauty Co",
  "platform": "woocommerce",
  "store_url": "https://seoulbeauty.com",
  "pack_id": "kbeauty",
  "public_key": "...",
  "created_at": "2026-06-11T10:00:00Z"
}
```

### `PATCH /v1/tenants/{tenant_id}`

Updates `name` and/or `pack_id`. Both optional. Returns updated tenant detail.

```json
{ "pack_id": "haircare" }
```

Both endpoints return `404` if tenant not found, `401` if provision key invalid.

**New CRUD functions needed:**
- `get_tenant_by_id(session, tenant_id) -> Tenant | None` — may already exist; verify and add if missing
- `update_tenant(session, tenant, **fields) -> Tenant`

---

## 4. Job status endpoints (P3-4)

The `Job` model is fully defined but has no read path. Operators need this to monitor syncs.

### `GET /v1/jobs/{job_id}`

Auth: `X-eShopeo-Tenant-Key` (tenant scoped — tenants can only see their own jobs).

Response:
```json
{
  "id": "...",
  "type": "product_sync",
  "status": "running",
  "progress": 150,
  "total": 500,
  "error": null,
  "started_at": "2026-06-11T10:01:00Z",
  "finished_at": null
}
```

Returns `404` if job not found or belongs to a different tenant.

### `GET /v1/jobs`

Auth: `X-eShopeo-Tenant-Key`. Optional query param `type` to filter. Returns list, most recent first, limit 50.

**New CRUD functions:**
- `get_job(session, tenant_id, job_id) -> Job | None`
- `list_jobs(session, tenant_id, type=None, limit=50) -> list[Job]`

---

## 5. Widget JS embed (P3-5)

### `GET /v1/widget/embed.js`

Query param: `key=<public_key>` (the tenant's `public_key` UUID).

Returns vanilla JS with `Content-Type: application/javascript`. No auth required — the public_key is safe to expose client-side (it's a non-secret identifier).

The script:
1. Reads `key` from its own `src` URL params (or falls back to a `data-eshopeo-key` attribute on the script tag)
2. Calls `/v1/widget/session` with `X-eShopeo-Tenant-Key: <key>` to get a JWT
3. Injects a floating button (bottom-right) and a chat panel (hidden by default)
4. On button click: toggles the panel
5. On submit: POSTs to `/v1/widget/chat` with the JWT in `Authorization: Bearer <token>`
6. Displays the response text in the panel
7. Re-fetches a session token if the current one expires (15-minute TTL)

The script is ~150 lines of vanilla JS (no dependencies, no bundler). It uses `fetch`, CSS injected via a `<style>` tag, and `localStorage` to persist the session token between page loads within the TTL window.

### `GET /v1/widget/demo.html`

Returns a minimal HTML page that includes the embed script (for development/testing). Auth: none. Should only render in `development` environment (returns `404` in production).

---

## 6. File map

**New files:**
- `services/core/eshopeo/db/migrations/versions/0002_tenant_pack_id.py`
- `services/core/eshopeo/api/routers/jobs.py`

**Modified files:**
- `services/core/eshopeo/db/models.py` — add `pack_id: Mapped[Optional[str]]` to Tenant
- `services/core/eshopeo/db/crud/tenants.py` — add `get_tenant_by_id()` if missing, add `update_tenant()`; add `get_job()`, `list_jobs()` to new `jobs.py` CRUD file
- `services/core/eshopeo/db/crud/jobs.py` — new: `get_job()`, `list_jobs()`
- `services/core/eshopeo/packs/registry.py` — add `get_pack_for_tenant()`
- `services/core/eshopeo/api/routers/tenants.py` — add GET + PATCH endpoints
- `services/core/eshopeo/api/routers/widget.py` — replace `default_pack()` calls with `get_pack_for_tenant(tenant)`; add `embed.js` and `demo.html` endpoints
- `services/core/eshopeo/api/routers/search.py` — replace `default_pack()` call
- `services/core/eshopeo/api/routers/sync.py` — replace `default_pack()` call
- `services/core/eshopeo/api/app.py` — register jobs router

**New tests:**
- `services/core/tests/test_tenant_pack.py` — pack routing (4 tests)
- `services/core/tests/test_tenant_management.py` — GET + PATCH tenant endpoints (5 tests)
- `services/core/tests/test_jobs_endpoint.py` — GET job, GET jobs list (4 tests)
- `services/core/tests/test_widget_embed.py` — embed.js served, demo.html dev-only (4 tests)

---

## 7. Security constraints

- `pack_id` on tenant is operator-set (via provision key) — tenants/customers cannot self-select their pack
- Job read endpoints are tenant-scoped — a tenant cannot query another tenant's jobs
- Widget `embed.js` key param is the `public_key` (UUID, non-secret) — safe to embed in `<script src>`
- Widget demo endpoint disabled in production (prevents information disclosure)
- No new PII surfaces introduced

---

## 8. Migration safety

`pack_id` column is nullable. All existing rows remain valid — application falls back to `"kbeauty"` when `pack_id IS NULL`. No data migration needed.
