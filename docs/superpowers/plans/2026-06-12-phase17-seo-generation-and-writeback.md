# Phase 17 — SEO Metadata Generation & Platform Write-back Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add AI-generated SEO metadata (meta_title, meta_description) as ContentDraft rows, and wire platform write-back into the approve endpoint so approved `description_html` drafts are automatically pushed to the live WooCommerce or Shopify store.

**Architecture:** Three independent additions. P17-1 adds the SEO Celery task + two new API endpoints + one CRUD generalisation. P17-2 creates the write-back client as a standalone async module. P17-3 wires write-back into the existing approve endpoint by adding a `?field=` param and a `platform_synced` field to the response.

**Tech Stack:** FastAPI, SQLAlchemy async, Celery, Pydantic v2, `httpx` (already in requirements), `cryptography.fernet` (already installed), `structlog`.

---

## Key conventions (read before touching any file)

- `asyncio_mode = "auto"` in pyproject.toml — **NEVER add `@pytest.mark.asyncio`**
- Patch at where the name is USED: `eshopeo.api.routers.content.get_content_draft`, not `eshopeo.db.crud.content.get_content_draft`
- `app.dependency_overrides[dep] = lambda: mock` + `app.dependency_overrides.clear()` after each test
- For endpoints that call `db.add()` or `db.commit()`, override `get_db` with `AsyncMock()` whose `.add = MagicMock()` and `.commit = AsyncMock()`
- `conftest.py` at `services/core/tests/conftest.py` — import `make_test_settings` from `tests.conftest`
- `make_test_settings()` returns a real `Settings` instance including a real Fernet key in `credential_encryption_key`
- Test the `_async` helper directly (not the Celery task wrapper)

---

## Task P17-1: SEO metadata generation task + CRUD + router

**Files:**
- Create: `services/core/eshopeo/workers/tasks/seo.py`
- Modify: `services/core/eshopeo/db/crud/content.py` — add `field` param to `list_products_without_draft`
- Modify: `services/core/eshopeo/api/routers/content.py` — `?field=` on GET draft, 2 new endpoints
- Create: `services/core/tests/test_seo_generation.py`

- [ ] **Step 1: Write the 3 failing tests**

Create `services/core/tests/test_seo_generation.py`:

```python
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db, get_tenant
from eshopeo.db.models import Tenant
from eshopeo.workers.tasks.seo import _generate_seo_async, SeoMeta
from tests.conftest import make_test_settings


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    return t


async def test_generate_seo_async_upserts_two_drafts():
    tenant_id = uuid4()
    product_id = uuid4()

    mock_tenant = MagicMock()
    mock_tenant.id = tenant_id
    mock_tenant.pack_id = "kbeauty"

    mock_product = MagicMock()
    mock_product.id = product_id
    mock_product.title = "Vitamin C Serum"
    mock_product.price_minor = 3500
    mock_product.currency = "USD"
    mock_product.categories = ["serum"]
    mock_product.domain_attributes = {"spf": 0, "ingredients": "ascorbic acid"}

    mock_result = SeoMeta(meta_title="Vitamin C Serum", meta_description="Brightening serum")

    mock_session = AsyncMock()
    mock_session.commit = AsyncMock()

    with (
        patch("eshopeo.workers.tasks.seo.get_tenant_by_id", new_callable=AsyncMock, return_value=mock_tenant),
        patch("eshopeo.workers.tasks.seo.get_product_by_id", new_callable=AsyncMock, return_value=mock_product),
        patch("eshopeo.workers.tasks.seo.upsert_content_draft", new_callable=AsyncMock) as mock_upsert,
        patch("eshopeo.workers.tasks.seo.LLMGateway") as mock_gw_cls,
        patch("eshopeo.workers.tasks.seo.async_session_factory") as mock_factory,
        patch("eshopeo.workers.tasks.seo.get_settings", return_value=MagicMock()),
    ):
        mock_gw = AsyncMock()
        mock_gw.complete = AsyncMock(return_value=mock_result)
        mock_gw_cls.return_value = mock_gw
        mock_factory.return_value.__aenter__ = AsyncMock(return_value=mock_session)
        mock_factory.return_value.__aexit__ = AsyncMock(return_value=False)

        await _generate_seo_async(str(tenant_id), str(product_id))

    assert mock_upsert.call_count == 2
    calls = {c.args[2]: c.args[3] for c in mock_upsert.call_args_list}
    assert "meta_title" in calls
    assert "meta_description" in calls
    mock_session.commit.assert_called_once()


async def test_generate_seo_async_skips_when_product_not_found():
    tenant_id = uuid4()
    product_id = uuid4()
    mock_session = AsyncMock()

    with (
        patch("eshopeo.workers.tasks.seo.get_tenant_by_id", new_callable=AsyncMock, return_value=MagicMock()),
        patch("eshopeo.workers.tasks.seo.get_product_by_id", new_callable=AsyncMock, return_value=None),
        patch("eshopeo.workers.tasks.seo.upsert_content_draft", new_callable=AsyncMock) as mock_upsert,
        patch("eshopeo.workers.tasks.seo.async_session_factory") as mock_factory,
        patch("eshopeo.workers.tasks.seo.get_settings", return_value=MagicMock()),
    ):
        mock_factory.return_value.__aenter__ = AsyncMock(return_value=mock_session)
        mock_factory.return_value.__aexit__ = AsyncMock(return_value=False)
        await _generate_seo_async(str(tenant_id), str(product_id))

    mock_upsert.assert_not_called()


def test_generate_seo_endpoint_queues_task():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)
    tenant = _make_tenant()
    product_id = uuid4()

    mock_product = MagicMock()

    app.dependency_overrides[get_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = lambda: AsyncMock()

    with (
        patch("eshopeo.api.routers.content.get_product_by_id", new_callable=AsyncMock, return_value=mock_product),
        patch("eshopeo.api.routers.content.generate_seo_metadata") as mock_task,
    ):
        mock_task.delay = MagicMock()
        r = client.post(f"/v1/content/products/{product_id}/generate-seo")

    app.dependency_overrides.clear()

    assert r.status_code == 202
    assert r.json()["queued"] is True
    mock_task.delay.assert_called_once_with(str(tenant.id), str(product_id))
```

- [ ] **Step 2: Run tests — expect FAIL (module not found)**

```
cd services/core && python -m pytest tests/test_seo_generation.py -v 2>&1 | head -40
```

Expected: `ModuleNotFoundError` or `ImportError` — `eshopeo.workers.tasks.seo` does not exist yet.

- [ ] **Step 3: Create `eshopeo/workers/tasks/seo.py`**

```python
import asyncio
from uuid import UUID

import structlog
from pydantic import BaseModel

from eshopeo.workers.celery_app import celery_app
from eshopeo.config import get_settings
from eshopeo.db.engine import async_session_factory
from eshopeo.db.crud.products import get_product_by_id
from eshopeo.db.crud.tenants import get_tenant_by_id
from eshopeo.db.crud.content import upsert_content_draft
from eshopeo.llm.gateway import LLMGateway, LLMParseError, ModelTier

logger = structlog.get_logger(__name__)


class SeoMeta(BaseModel):
    meta_title: str
    meta_description: str


_SYSTEM_PROMPT = (
    "You are an SEO specialist. Write a meta title (max 60 characters) and meta description "
    "(max 155 characters) for the product below. Focus on the primary benefit and key "
    "attributes. No keyword stuffing. Return only valid JSON."
)


def _build_seo_user_prompt(product) -> str:
    attrs = product.domain_attributes or {}
    attr_lines = "\n".join(f"- {k}: {v}" for k, v in attrs.items() if v is not None)
    price = product.price_minor / 100
    cats = ", ".join(product.categories or [])
    return (
        f"Product SEO for:\n\n"
        f"Title: {product.title}\n"
        f"Price: {price:.2f} {product.currency}\n"
        f"Categories: {cats}\n"
        f"Attributes:\n{attr_lines}\n\n"
        f"Return JSON with 'meta_title' (max 60 chars) and 'meta_description' (max 155 chars)."
    )


async def _generate_seo_async(tenant_id_str: str, product_id_str: str) -> None:
    tenant_id = UUID(tenant_id_str)
    product_id = UUID(product_id_str)
    settings = get_settings()

    async with async_session_factory() as session:
        tenant = await get_tenant_by_id(session, tenant_id)
        product = await get_product_by_id(session, tenant_id, product_id)
        if not tenant or not product:
            logger.warning(
                "generate_seo_not_found",
                tenant_id=tenant_id_str,
                product_id=product_id_str,
            )
            return

        gateway = LLMGateway(settings, tenant_id)
        result = await gateway.complete(
            ModelTier.GENERATE,
            _SYSTEM_PROMPT,
            _build_seo_user_prompt(product),
            SeoMeta,
            max_tokens=512,
        )

        await upsert_content_draft(session, tenant_id, product_id, "meta_title", result.meta_title)
        await upsert_content_draft(session, tenant_id, product_id, "meta_description", result.meta_description)
        await session.commit()
        logger.info("generate_seo_done", product_id=product_id_str)


@celery_app.task(
    bind=True,
    max_retries=3,
    default_retry_delay=60,
    name="eshopeo.workers.tasks.seo.generate_seo_metadata",
)
def generate_seo_metadata(self, tenant_id_str: str, product_id_str: str) -> None:
    try:
        asyncio.run(_generate_seo_async(tenant_id_str, product_id_str))
    except LLMParseError as exc:
        logger.error("generate_seo_parse_failure", product_id=product_id_str, error=str(exc))
    except Exception as exc:
        raise self.retry(exc=exc)
```

- [ ] **Step 4: Generalise `list_products_without_draft` in `eshopeo/db/crud/content.py`**

Replace the existing function (lines 63-80) with:

```python
async def list_products_without_draft(
    session: AsyncSession, tenant_id: UUID, field: str = "description_html"
) -> list[Product]:
    subq = (
        select(ContentDraft.product_id)
        .where(
            ContentDraft.tenant_id == tenant_id,
            ContentDraft.field == field,
        )
        .scalar_subquery()
    )
    result = await session.execute(
        select(Product).where(
            Product.tenant_id == tenant_id,
            Product.id.not_in(subq),
        )
    )
    return list(result.scalars().all())
```

- [ ] **Step 5: Add `?field=` to GET draft + 2 new endpoints in `eshopeo/api/routers/content.py`**

Add import at top (after existing imports):
```python
from eshopeo.workers.tasks.seo import generate_seo_metadata
```

Add `SeoGenerateResponse` model (after `BulkGenerateResponse`):
```python
class SeoGenerateResponse(BaseModel):
    product_id: str
    queued: bool
```

Replace the existing `get_product_draft` endpoint:
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
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="No draft found for this product")
    return _draft_out(draft)
```

Add new endpoints **before** the existing `@router.post("/bulk-generate", ...)` endpoint, and **after** the existing `get_product_draft` endpoint:

```python
@router.post("/products/{product_id}/generate-seo",
             response_model=SeoGenerateResponse, status_code=202)
async def generate_product_seo(
    product_id: UUID,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> SeoGenerateResponse:
    product = await get_product_by_id(db, tenant.id, product_id)
    if product is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Product not found")
    generate_seo_metadata.delay(str(tenant.id), str(product_id))
    return SeoGenerateResponse(product_id=str(product_id), queued=True)
```

Add bulk SEO endpoint after `bulk_generate_endpoint` (keeping `bulk-generate-seo` as a literal path — FastAPI routes match in registration order, and both `bulk-generate` and `bulk-generate-seo` are literals registered before any `/products/{id}/...` routes):

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

- [ ] **Step 6: Run tests — expect PASS**

```
cd services/core && python -m pytest tests/test_seo_generation.py -v
```

Expected: 3 passed.

- [ ] **Step 7: Run full suite — verify no regressions**

```
cd services/core && python -m pytest --tb=short -q 2>&1 | tail -20
```

Expected: same number of failures as Phase 16 end-state (17 infra-only). No new failures.

- [ ] **Step 8: Commit**

```
git add services/core/eshopeo/workers/tasks/seo.py \
        services/core/eshopeo/db/crud/content.py \
        services/core/eshopeo/api/routers/content.py \
        services/core/tests/test_seo_generation.py
git commit -m "feat(p17-1): SEO metadata generation task + bulk-generate-seo endpoints"
```

---

## Task P17-2: Platform write-back client

**Files:**
- Create: `services/core/eshopeo/connectors/__init__.py` (empty)
- Create: `services/core/eshopeo/connectors/writeback.py`
- Create: `services/core/tests/test_writeback.py`

- [ ] **Step 1: Write the 3 failing tests**

Create `services/core/tests/test_writeback.py`:

```python
import base64
import json
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

import pytest
from cryptography.fernet import Fernet

from eshopeo.connectors.writeback import write_back_to_platform
from tests.conftest import make_test_settings


def _make_tenant(platform: str, store_url: str, creds: dict, key: bytes) -> MagicMock:
    f = Fernet(key)
    tenant = MagicMock()
    tenant.id = uuid4()
    tenant.platform = platform
    tenant.store_url = store_url
    tenant.credentials_enc = f.encrypt(json.dumps(creds).encode())
    return tenant


async def test_write_back_woocommerce_success():
    settings = make_test_settings()
    key = settings.credential_encryption_key.get_secret_value().encode()
    creds = {"consumer_key": "ck_test", "consumer_secret": "cs_test"}
    tenant = _make_tenant("woocommerce", "https://shop.example.com", creds, key)

    mock_response = MagicMock()
    mock_response.raise_for_status = MagicMock()

    mock_client = AsyncMock()
    mock_client.__aenter__ = AsyncMock(return_value=mock_client)
    mock_client.__aexit__ = AsyncMock(return_value=False)
    mock_client.put = AsyncMock(return_value=mock_response)

    with patch("eshopeo.connectors.writeback.httpx.AsyncClient", return_value=mock_client):
        result = await write_back_to_platform(
            tenant, "123", "description_html", "<p>New</p>", settings
        )

    assert result is True
    mock_client.put.assert_called_once()
    call_kwargs = mock_client.put.call_args
    assert "wp-json/wc/v3/products/123" in call_kwargs.args[0]
    assert call_kwargs.kwargs["json"] == {"description": "<p>New</p>"}


async def test_write_back_shopify_success():
    settings = make_test_settings()
    key = settings.credential_encryption_key.get_secret_value().encode()
    creds = {"access_token": "shpat_test"}
    tenant = _make_tenant("shopify", "https://mystore.myshopify.com", creds, key)

    mock_response = MagicMock()
    mock_response.raise_for_status = MagicMock()

    mock_client = AsyncMock()
    mock_client.__aenter__ = AsyncMock(return_value=mock_client)
    mock_client.__aexit__ = AsyncMock(return_value=False)
    mock_client.put = AsyncMock(return_value=mock_response)

    with patch("eshopeo.connectors.writeback.httpx.AsyncClient", return_value=mock_client):
        result = await write_back_to_platform(
            tenant, "456", "description_html", "<p>New</p>", settings
        )

    assert result is True
    mock_client.put.assert_called_once()
    call_kwargs = mock_client.put.call_args
    assert "admin/api/2024-01/products/456.json" in call_kwargs.args[0]
    assert call_kwargs.kwargs["json"] == {"product": {"body_html": "<p>New</p>"}}


async def test_write_back_unknown_platform_returns_false():
    settings = make_test_settings()
    key = settings.credential_encryption_key.get_secret_value().encode()
    creds = {"token": "abc"}
    tenant = _make_tenant("magento", "https://shop.example.com", creds, key)

    result = await write_back_to_platform(
        tenant, "789", "description_html", "<p>New</p>", settings
    )

    assert result is False
```

- [ ] **Step 2: Run tests — expect FAIL (module not found)**

```
cd services/core && python -m pytest tests/test_writeback.py -v 2>&1 | head -20
```

Expected: `ModuleNotFoundError` — `eshopeo.connectors.writeback` does not exist.

- [ ] **Step 3: Create `eshopeo/connectors/__init__.py`**

Create an empty file at `services/core/eshopeo/connectors/__init__.py`.

- [ ] **Step 4: Create `eshopeo/connectors/writeback.py`**

```python
import base64
import json

import httpx
import structlog
from cryptography.fernet import Fernet

from eshopeo.config import Settings
from eshopeo.db.models import Tenant

logger = structlog.get_logger(__name__)

_SUPPORTED_FIELDS = {"description_html"}


async def write_back_to_platform(
    tenant: Tenant,
    platform_id: str,
    field: str,
    text: str,
    settings: Settings,
) -> bool:
    if field not in _SUPPORTED_FIELDS:
        return False
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

        logger.info(
            "write_back_success",
            platform=tenant.platform,
            product_platform_id=platform_id,
            field=field,
        )
        return True

    except Exception as exc:
        logger.warning(
            "write_back_failed",
            platform=getattr(tenant, "platform", "unknown"),
            product_platform_id=platform_id,
            field=field,
            error=str(exc),
        )
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

- [ ] **Step 5: Run tests — expect PASS**

```
cd services/core && python -m pytest tests/test_writeback.py -v
```

Expected: 3 passed.

- [ ] **Step 6: Run full suite — verify no regressions**

```
cd services/core && python -m pytest --tb=short -q 2>&1 | tail -20
```

Expected: same failure count as before (17 infra-only). No new failures.

- [ ] **Step 7: Commit**

```
git add services/core/eshopeo/connectors/__init__.py \
        services/core/eshopeo/connectors/writeback.py \
        services/core/tests/test_writeback.py
git commit -m "feat(p17-2): platform write-back client (WooCommerce + Shopify)"
```

---

## Task P17-3: Wire write-back into approve endpoint

**Files:**
- Modify: `services/core/eshopeo/api/routers/content.py` — add `?field=` to approve, `ApproveDraftOut`, write-back call
- Create: `services/core/tests/test_content_approve_writeback.py`

- [ ] **Step 1: Write the 3 failing tests**

Create `services/core/tests/test_content_approve_writeback.py`:

```python
from datetime import datetime, timezone
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from eshopeo.api.app import create_app
from eshopeo.api.deps import get_db, get_tenant
from eshopeo.db.models import ContentDraft, Product, Tenant
from tests.conftest import make_test_settings


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    t.platform = "woocommerce"
    t.store_url = "https://shop.example.com"
    t.credentials_enc = b"encrypted"
    return t


def _make_draft(tenant_id, product_id, field="description_html", status="pending"):
    d = MagicMock(spec=ContentDraft)
    d.product_id = product_id
    d.tenant_id = tenant_id
    d.field = field
    d.draft_text = "<p>Generated</p>"
    d.status = status
    d.created_at = datetime(2026, 6, 12, tzinfo=timezone.utc)
    d.approved_at = None
    return d


def test_approve_description_draft_triggers_writeback():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product_id = uuid4()
    draft = _make_draft(tenant.id, product_id)
    approved_draft = _make_draft(tenant.id, product_id, status="approved")
    approved_draft.approved_at = datetime(2026, 6, 12, 1, 0, tzinfo=timezone.utc)

    mock_product = MagicMock(spec=Product)
    mock_product.platform_id = "wc-123"
    mock_product.description_html = None

    mock_db = AsyncMock()
    mock_db.add = MagicMock()
    mock_db.commit = AsyncMock()

    app.dependency_overrides[get_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = lambda: mock_db

    with (
        patch("eshopeo.api.routers.content.get_content_draft", new_callable=AsyncMock, return_value=draft),
        patch("eshopeo.api.routers.content.get_product_by_id", new_callable=AsyncMock, return_value=mock_product),
        patch("eshopeo.api.routers.content.approve_content_draft", new_callable=AsyncMock, return_value=approved_draft),
        patch("eshopeo.api.routers.content.write_back_to_platform", new_callable=AsyncMock, return_value=True) as mock_wb,
        patch("eshopeo.api.routers.content.get_settings", return_value=MagicMock()),
    ):
        r = client.post(f"/v1/content/products/{product_id}/draft/approve")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    body = r.json()
    assert body["status"] == "approved"
    assert body["platform_synced"] is True
    mock_wb.assert_called_once()


def test_approve_writeback_failure_still_returns_200():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product_id = uuid4()
    draft = _make_draft(tenant.id, product_id)
    approved_draft = _make_draft(tenant.id, product_id, status="approved")
    approved_draft.approved_at = datetime(2026, 6, 12, 1, 0, tzinfo=timezone.utc)

    mock_product = MagicMock(spec=Product)
    mock_product.platform_id = "wc-123"
    mock_product.description_html = None

    mock_db = AsyncMock()
    mock_db.add = MagicMock()
    mock_db.commit = AsyncMock()

    app.dependency_overrides[get_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = lambda: mock_db

    with (
        patch("eshopeo.api.routers.content.get_content_draft", new_callable=AsyncMock, return_value=draft),
        patch("eshopeo.api.routers.content.get_product_by_id", new_callable=AsyncMock, return_value=mock_product),
        patch("eshopeo.api.routers.content.approve_content_draft", new_callable=AsyncMock, return_value=approved_draft),
        patch("eshopeo.api.routers.content.write_back_to_platform", new_callable=AsyncMock, return_value=False),
        patch("eshopeo.api.routers.content.get_settings", return_value=MagicMock()),
    ):
        r = client.post(f"/v1/content/products/{product_id}/draft/approve")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["platform_synced"] is False


def test_approve_seo_field_skips_writeback():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    tenant = _make_tenant()
    product_id = uuid4()
    draft = _make_draft(tenant.id, product_id, field="meta_title")
    approved_draft = _make_draft(tenant.id, product_id, field="meta_title", status="approved")
    approved_draft.approved_at = datetime(2026, 6, 12, 1, 0, tzinfo=timezone.utc)

    mock_db = AsyncMock()
    mock_db.add = MagicMock()
    mock_db.commit = AsyncMock()

    app.dependency_overrides[get_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = lambda: mock_db

    with (
        patch("eshopeo.api.routers.content.get_content_draft", new_callable=AsyncMock, return_value=draft),
        patch("eshopeo.api.routers.content.approve_content_draft", new_callable=AsyncMock, return_value=approved_draft),
        patch("eshopeo.api.routers.content.write_back_to_platform", new_callable=AsyncMock) as mock_wb,
    ):
        r = client.post(f"/v1/content/products/{product_id}/draft/approve?field=meta_title")

    app.dependency_overrides.clear()

    assert r.status_code == 200
    assert r.json()["platform_synced"] is False
    mock_wb.assert_not_called()
```

- [ ] **Step 2: Run tests — expect FAIL**

```
cd services/core && python -m pytest tests/test_content_approve_writeback.py -v 2>&1 | head -40
```

Expected: failures because `write_back_to_platform` import doesn't exist in the router yet, and `ApproveDraftOut` doesn't exist.

- [ ] **Step 3: Update `eshopeo/api/routers/content.py`**

Add to imports at top:
```python
from eshopeo.config import get_settings
from eshopeo.connectors.writeback import write_back_to_platform
```

Add `ApproveDraftOut` model (after `ContentDraftListResponse`):
```python
class ApproveDraftOut(BaseModel):
    product_id: str
    field: str
    draft_text: str
    status: str
    created_at: str
    approved_at: str | None
    platform_synced: bool
```

Replace the existing `approve_product_draft` endpoint entirely:
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
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="No draft found for this product")
    if draft.status == "approved":
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Draft already approved")

    if field == "description_html":
        product = await get_product_by_id(db, tenant.id, product_id)
        product.description_html = draft.draft_text
        db.add(product)

    draft = await approve_content_draft(db, draft)
    await db.commit()

    platform_synced = False
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

- [ ] **Step 4: Run tests — expect PASS**

```
cd services/core && python -m pytest tests/test_content_approve_writeback.py -v
```

Expected: 3 passed.

- [ ] **Step 5: Check the old approve tests still pass**

```
cd services/core && python -m pytest tests/test_content_approve_endpoint.py -v
```

The old tests POST to `/v1/content/products/{id}/draft/approve` without `?field=` — this still works because `field` defaults to `"description_html"`. The response now has `ApproveDraftOut` shape instead of `ContentDraftOut`, but `platform_synced` is an extra field that doesn't break existing response assertions.

**If `test_content_approve_endpoint.py` fails**, the fix is: the old tests don't mock `write_back_to_platform` or `get_settings`. Add those patches. The old tests also don't override `get_db` with a commit-capable mock — if a `db.commit()` error surfaces, fix by making `mock_db.commit = AsyncMock()`.

Add to both passing tests in `test_content_approve_endpoint.py` (wrap existing patches with):
```python
with (
    patch("eshopeo.api.routers.content.get_content_draft", ...),
    patch("eshopeo.api.routers.content.get_product_by_id", ...),
    patch("eshopeo.api.routers.content.approve_content_draft", ...),
    patch("eshopeo.api.routers.content.write_back_to_platform", new_callable=AsyncMock, return_value=False),
    patch("eshopeo.api.routers.content.get_settings", return_value=MagicMock()),
):
```

- [ ] **Step 6: Run full suite**

```
cd services/core && python -m pytest --tb=short -q 2>&1 | tail -20
```

Expected: same infra-only failures (17). New passing tests: 3 from writeback + 3 from seo + 3 from content_approve_writeback = 9 new tests passing.

- [ ] **Step 7: Commit**

```
git add services/core/eshopeo/api/routers/content.py \
        services/core/tests/test_content_approve_writeback.py \
        services/core/tests/test_content_approve_endpoint.py
git commit -m "feat(p17-3): wire write-back into approve endpoint; ApproveDraftOut with platform_synced"
```

---

## Task P17-4: Full suite + PROGRESS.md

**Files:**
- Modify: `docs/PROGRESS.md`

- [ ] **Step 1: Run the full test suite and record results**

```
cd services/core && python -m pytest --tb=short -q 2>&1 | tail -30
```

Record: total tests, passed, failed (infra-only vs code failures).

- [ ] **Step 2: Update `docs/PROGRESS.md`**

Update the Phase 17 entry:

```markdown
## Phase 17 — SEO Metadata Generation & Platform Write-back (Complete)

**Date:** 2026-06-12

### What was built
- `eshopeo/workers/tasks/seo.py` — `generate_seo_metadata` Celery task; one LLM call produces `meta_title` + `meta_description` stored as two separate ContentDraft rows
- `eshopeo/connectors/writeback.py` — `write_back_to_platform`; WooCommerce (Basic auth) and Shopify (access token) write-back; never raises; returns bool
- `eshopeo/db/crud/content.py` — `list_products_without_draft(field=)` generalised (backwards-compatible default `"description_html"`)
- `eshopeo/api/routers/content.py` — `?field=` on GET draft; `POST /v1/content/products/{id}/generate-seo` (202); `POST /v1/content/bulk-generate-seo`; approve endpoint wired with write-back; `ApproveDraftOut` adds `platform_synced: bool`

### Test counts
- Tests: [total] total, [passed] passed, [failed] failed (infra-only)
- New tests this phase: 9
```

- [ ] **Step 3: Commit**

```
git add docs/PROGRESS.md
git commit -m "docs(p17-4): update PROGRESS.md for Phase 17 completion"
```
