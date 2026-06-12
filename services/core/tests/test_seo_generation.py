from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from fastapi.testclient import TestClient

from helix.api.app import create_app
from helix.api.deps import get_db, get_tenant
from helix.db.models import Product, Tenant
from helix.workers.tasks.seo import SeoMeta, _generate_seo_async
from tests.conftest import make_test_settings


def _make_tenant():
    t = MagicMock(spec=Tenant)
    t.id = uuid4()
    t.public_key = uuid4()
    return t


def _make_product(tenant_id):
    p = MagicMock(spec=Product)
    p.id = uuid4()
    p.tenant_id = tenant_id
    p.title = "Hydrating Serum"
    p.price_minor = 3500
    p.currency = "USD"
    p.categories = ["serum"]
    p.domain_attributes = {"skin_type": "dry"}
    return p


async def test_generate_seo_async_upserts_two_drafts():
    tenant_id = uuid4()
    product_id = uuid4()

    mock_tenant = MagicMock()
    mock_tenant.id = tenant_id

    mock_product = MagicMock()
    mock_product.id = product_id
    mock_product.title = "Hydrating Serum"
    mock_product.price_minor = 3500
    mock_product.currency = "USD"
    mock_product.categories = ["serum"]
    mock_product.domain_attributes = {"skin_type": "dry"}

    mock_seo_result = SeoMeta(
        meta_title="Hydrating Serum for Dry Skin",
        meta_description="A deeply hydrating serum formulated for dry skin types.",
    )

    mock_session = AsyncMock()
    mock_session.commit = AsyncMock()

    with (
        patch("helix.workers.tasks.seo.get_tenant_by_id", new_callable=AsyncMock, return_value=mock_tenant),
        patch("helix.workers.tasks.seo.get_product_by_id", new_callable=AsyncMock, return_value=mock_product),
        patch("helix.workers.tasks.seo.upsert_content_draft", new_callable=AsyncMock) as mock_upsert,
        patch("helix.workers.tasks.seo.LLMGateway") as mock_gw_cls,
        patch("helix.workers.tasks.seo.async_session_factory") as mock_factory,
        patch("helix.workers.tasks.seo.get_settings", return_value=MagicMock()),
    ):
        mock_gw = AsyncMock()
        mock_gw.complete = AsyncMock(return_value=mock_seo_result)
        mock_gw_cls.return_value = mock_gw

        mock_factory.return_value.__aenter__ = AsyncMock(return_value=mock_session)
        mock_factory.return_value.__aexit__ = AsyncMock(return_value=False)

        await _generate_seo_async(str(tenant_id), str(product_id))

    assert mock_upsert.call_count == 2
    # upsert_content_draft(session, tenant_id, product_id, field, draft_text)
    # args[3] = field, args[4] = draft_text
    calls_by_field = {c.args[3]: c.args[4] for c in mock_upsert.call_args_list}
    assert "meta_title" in calls_by_field
    assert "meta_description" in calls_by_field
    assert calls_by_field["meta_title"] == mock_seo_result.meta_title
    assert calls_by_field["meta_description"] == mock_seo_result.meta_description
    mock_session.commit.assert_called_once()


async def test_generate_seo_async_skips_when_product_not_found():
    tenant_id = uuid4()
    product_id = uuid4()

    mock_session = AsyncMock()
    mock_session.commit = AsyncMock()

    with (
        patch("helix.workers.tasks.seo.get_tenant_by_id", new_callable=AsyncMock, return_value=MagicMock()),
        patch("helix.workers.tasks.seo.get_product_by_id", new_callable=AsyncMock, return_value=None),
        patch("helix.workers.tasks.seo.upsert_content_draft", new_callable=AsyncMock) as mock_upsert,
        patch("helix.workers.tasks.seo.async_session_factory") as mock_factory,
        patch("helix.workers.tasks.seo.get_settings", return_value=MagicMock()),
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
    product = _make_product(tenant.id)
    product_id = product.id

    mock_db = AsyncMock()

    async def _override_db():
        yield mock_db

    app.dependency_overrides[get_tenant] = lambda: tenant
    app.dependency_overrides[get_db] = _override_db

    mock_task = MagicMock()
    mock_task.delay = MagicMock()

    with (
        patch("helix.api.routers.content.get_product_by_id", new_callable=AsyncMock, return_value=product),
        patch("helix.api.routers.content.generate_seo_metadata", mock_task),
    ):
        r = client.post(f"/v1/content/products/{product_id}/generate-seo")

    app.dependency_overrides.clear()

    assert r.status_code == 202
    assert r.json()["queued"] is True
    mock_task.delay.assert_called_once_with(str(tenant.id), str(product_id))
