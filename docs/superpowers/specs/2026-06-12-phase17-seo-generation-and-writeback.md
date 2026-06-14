# Phase 17 — SEO Metadata Generation & Platform Write-back Design Spec

**Date:** 2026-06-12
**Status:** Approved
**Scope:** Two tightly related capabilities that close the content loop: (1) AI-generated SEO metadata (`meta_title`, `meta_description`) stored as ContentDraft rows alongside the existing description drafts, and (2) platform write-back — when a `description_html` draft is approved, Helix pushes the content directly to the merchant's WooCommerce or Shopify store via their respective REST APIs, eliminating the manual copy-paste step.
**Definition of done:** A merchant can trigger SEO generation for one or all products, retrieve the generated meta fields via the draft endpoint, approve any field type, and have `description_html` approvals automatically reflected in their live store.

---

## 1. Gap analysis from Phase 16

| Gap | Impact |
|-----|--------|
| Description approval is Helix-only — merchant still has to copy-paste to the live store | The content workflow is incomplete; the approved text isn't actually live without manual intervention |
| No SEO metadata generation | Product pages lack AI-generated titles and descriptions for search engines |
| Draft GET endpoint hardcodes `field="description_html"` | Merchants can't retrieve meta_title / meta_description drafts through the API |
| Approve endpoint hardcodes `field="description_html"` | Only description drafts can be approved — no path for future field types |

**Already done:** `ContentDraft.field` column already supports arbitrary field names. `httpx` is in pyproject.toml. `cryptography.fernet` is available. Credential decryption pattern exists (used in sync endpoints). `list_products_without_draft` has a hardcoded `field="description_html"` that can be parameterised.

---

## 2. SEO metadata generation (P17-1)

### New Celery task — `helix/workers/tasks/seo.py`

One LLM call produces both `meta_title` and `meta_description`. They are stored as two separate ContentDraft rows (same pattern as description drafts).

```python
from pydantic import BaseModel

class SeoMeta(BaseModel):
    meta_title: str         # target ≤60 characters
    meta_description: str   # target ≤160 characters
```

System prompt (no pack copy guidance needed for SEO — it's structural):
```
You are an SEO specialist. Write a meta title (max 60 characters) and meta description
(max 155 characters) for the product below. Focus on the primary benefit and key
attributes. No keyword stuffing. Return only valid JSON.
```

User prompt: same structure as description — title, price, categories, domain_attributes
(None-safe: `if v is not None`).

Task:
```python
@celery_app.task(bind=True, max_retries=3, default_retry_delay=60,
                 name="helix.workers.tasks.seo.generate_seo_metadata")
def generate_seo_metadata(self, tenant_id_str: str, product_id_str: str) -> None:
    try:
        asyncio.run(_generate_seo_async(tenant_id_str, product_id_str))
    except LLMParseError as exc:
        logger.error("generate_seo_parse_failure", product_id=product_id_str, error=str(exc))
    except Exception as exc:
        raise self.retry(exc=exc)
```

`_generate_seo_async` calls `LLMGateway.complete(GENERATE, system, user, SeoMeta)` then:
```python
await upsert_content_draft(session, tenant_id, product_id, "meta_title", result.meta_title)
await upsert_content_draft(session, tenant_id, product_id, "meta_description", result.meta_description)
await session.commit()
```

### CRUD change — generalise `list_products_without_draft`

```python
# helix/db/crud/content.py — backwards-compatible (field defaults to "description_html")
async def list_products_without_draft(
    session: AsyncSession,
    tenant_id: UUID,
    field: str = "description_html",
) -> list[Product]:
    subq = (
        select(ContentDraft.product_id)
        .where(ContentDraft.tenant_id == tenant_id, ContentDraft.field == field)
        .scalar_subquery()
    )
    result = await session.execute(
        select(Product).where(Product.tenant_id == tenant_id, Product.id.not_in(subq))
    )
    return list(result.scalars().all())
```

### Content router additions

**Add `?field=` to `GET /v1/content/products/{product_id}/draft`** (backwards-compatible — defaults to `"description_html"`):

```python
@router.get("/products/{product_id}/draft", response_model=ContentDraftOut)
async def get_product_draft(
    product_id: UUID,
    field: str = Query(default="description_html"),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ContentDraftOut:
    draft = await get_content_draft(db, tenant.id, product_id, field=field)
    if draft is None:
        raise HTTPException(status_code=404, detail="No draft found for this product")
    return _draft_out(draft)
```

**New: `POST /v1/content/products/{product_id}/generate-seo`** (202):

```python
class SeoGenerateResponse(BaseModel):
    product_id: str
    queued: bool

@router.post("/products/{product_id}/generate-seo",
             response_model=SeoGenerateResponse, status_code=202)
async def generate_product_seo(
    product_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> SeoGenerateResponse:
    product = await get_product_by_id(db, tenant.id, product_id)
    if product is None:
        raise HTTPException(status_code=404, detail="Product not found")
    generate_seo_metadata.delay(str(tenant.id), str(product_id))
    return SeoGenerateResponse(product_id=str(product_id), queued=True)
```

**New: `POST /v1/content/bulk-generate-seo`**:

```python
@router.post("/bulk-generate-seo", response_model=BulkGenerateResponse)
async def bulk_generate_seo_endpoint(
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> BulkGenerateResponse:
    products = await list_products_without_draft(db, tenant.id, field="meta_title")
    for product in products:
        generate_seo_metadata.delay(str(tenant.id), str(product.id))
    return BulkGenerateResponse(queued=len(products))
```

Route ordering: `bulk-generate-seo` is a literal path — register **before** `/products/{product_id}/...` routes to avoid `bulk-generate-seo` being caught as a product_id.

---

## 3. Platform write-back client (P17-2)

New module `helix/connectors/writeback.py`. Supports WooCommerce and Shopify. **Never raises** — all failures are logged and a `bool` is returned.

```python
import base64, json, httpx, structlog
from cryptography.fernet import Fernet
from helix.config import Settings
from helix.db.models import Tenant

logger = structlog.get_logger(__name__)

_SUPPORTED_FIELDS = {"description_html"}   # SEO write-back added when platforms standardise


async def write_back_to_platform(
    tenant: Tenant,
    platform_id: str,
    field: str,
    text: str,
    settings: Settings,
) -> bool:
    """Push approved content back to the merchant's live store. Returns True on success."""
    if field not in _SUPPORTED_FIELDS:
        return False   # silently skip unsupported fields
    try:
        f = Fernet(settings.credential_encryption_key.get_secret_value().encode())
        creds = json.loads(f.decrypt(tenant.credentials_enc))

        if tenant.platform == "woocommerce":
            await _write_woocommerce(tenant.store_url, platform_id, field, text, creds)
        elif tenant.platform == "shopify":
            await _write_shopify(tenant.store_url, platform_id, field, text, creds)
        else:
            logger.warning("write_back_unsupported_platform", platform=tenant.platform)
            return False

        logger.info("write_back_success",
                    platform=tenant.platform, product_platform_id=platform_id, field=field)
        return True

    except Exception as exc:
        logger.warning("write_back_failed",
                       platform=tenant.platform, product_platform_id=platform_id,
                       field=field, error=str(exc))
        return False


async def _write_woocommerce(
    store_url: str, platform_id: str, field: str, text: str, creds: dict
) -> None:
    token = base64.b64encode(
        f"{creds['consumer_key']}:{creds['consumer_secret']}".encode()
    ).decode()
    payload = {"description": text} if field == "description_html" else {}
    async with httpx.AsyncClient(timeout=10.0) as client:
        r = await client.put(
            f"{store_url.rstrip('/')}/wp-json/wc/v3/products/{platform_id}",
            headers={"Authorization": f"Basic {token}"},
            json=payload,
        )
        r.raise_for_status()


async def _write_shopify(
    store_url: str, platform_id: str, field: str, text: str, creds: dict
) -> None:
    payload = {"product": {"body_html": text}} if field == "description_html" else {}
    async with httpx.AsyncClient(timeout=10.0) as client:
        r = await client.put(
            f"{store_url.rstrip('/')}/admin/api/2024-01/products/{platform_id}.json",
            headers={"X-Shopify-Access-Token": creds["access_token"]},
            json=payload,
        )
        r.raise_for_status()
```

**Credential format assumptions** (set at tenant provision time):
- WooCommerce: `{"consumer_key": "ck_...", "consumer_secret": "cs_..."}`
- Shopify: `{"access_token": "shpat_..."}`

**Write-back scope:** only `description_html` for now. WooCommerce meta fields require Yoast/RankMath REST extensions (external dependency). Shopify meta fields require a different API call. Both are future work.

---

## 4. Wire write-back into approve endpoint (P17-3)

### Add `?field=` to `POST /v1/content/products/{product_id}/draft/approve`

The approve endpoint needs to handle any field, not just `description_html`:

- If `field == "description_html"`: update `product.description_html` + attempt write-back
- Any other field: skip the product model update (no matching column), skip write-back

### New response model

```python
class ApproveDraftOut(BaseModel):
    product_id: str
    field: str
    draft_text: str
    status: str
    created_at: str
    approved_at: str | None
    platform_synced: bool   # True if write-back to live store succeeded
```

### Updated approve endpoint

```python
@router.post("/products/{product_id}/draft/approve", response_model=ApproveDraftOut)
async def approve_product_draft(
    product_id: UUID,
    field: str = Query(default="description_html"),
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> ApproveDraftOut:
    draft = await get_content_draft(db, tenant.id, product_id, field=field)
    if draft is None:
        raise HTTPException(status_code=404, detail="No draft found for this product")
    if draft.status == "approved":
        raise HTTPException(status_code=409, detail="Draft already approved")

    platform_synced = False
    if field == "description_html":
        product = await get_product_by_id(db, tenant.id, product_id)
        product.description_html = draft.draft_text
        db.add(product)

    draft = await approve_content_draft(db, draft)
    await db.commit()

    if field == "description_html":
        settings = get_settings()
        product_row = await get_product_by_id(db, tenant.id, product_id)
        platform_synced = await write_back_to_platform(
            tenant, product_row.platform_id, field, draft.draft_text, settings
        )

    return ApproveDraftOut(
        product_id=str(draft.product_id),
        field=draft.field,
        draft_text=draft.draft_text,
        status=draft.status,
        created_at=draft.created_at.isoformat(),
        approved_at=draft.approved_at.isoformat() if draft.approved_at else None,
        platform_synced=platform_synced,
    )
```

**Key design decisions:**
- `db.commit()` happens **before** write-back. The draft is approved in Helix regardless of whether the platform API is reachable.
- Write-back failure → `platform_synced=False`, response is still `200`. Helix is the source of truth; the merchant can retry.
- Write-back is **not** called for non-`description_html` fields — no platform API supports meta fields generically.

---

## 5. File map

**New files:**
- `services/core/helix/workers/tasks/seo.py` — `generate_seo_metadata` Celery task
- `services/core/helix/connectors/writeback.py` — `write_back_to_platform`
- `services/core/tests/test_seo_generation.py` — 3 tests
- `services/core/tests/test_writeback.py` — 3 tests
- `services/core/tests/test_content_approve_writeback.py` — 3 tests

**Modified files:**
- `services/core/helix/db/crud/content.py` — `list_products_without_draft` gets `field` param
- `services/core/helix/api/routers/content.py` — field param on GET draft + approve, 2 new endpoints, write-back import

---

## 6. Security constraints

- `credentials_enc` is decrypted only inside `write_back_to_platform` — never returned in any response, never logged
- `write_back_to_platform` never raises — exceptions are caught, logged without PII, and `False` is returned
- Write-back uses `timeout=10.0` to prevent slow platform APIs from blocking the event loop
- Only `description_html` field triggers write-back — no arbitrary text injection into other platform fields

---

## 7. Task breakdown

| Task | Description | Tests |
|------|-------------|-------|
| P17-1 | `generate_seo_metadata` Celery task in `seo.py`; generalise `list_products_without_draft(field=)`; add `?field=` to GET draft; add `POST /generate-seo` and `POST /bulk-generate-seo` to content router | 3 |
| P17-2 | `helix/connectors/writeback.py` — `write_back_to_platform`, `_write_woocommerce`, `_write_shopify`; never raises | 3 |
| P17-3 | Add `?field=` to approve endpoint; import and call `write_back_to_platform`; new `ApproveDraftOut` with `platform_synced`; commit before write-back | 3 |
| P17-4 | Full suite (expected 262 tests, 245 passing) + PROGRESS.md | — |
