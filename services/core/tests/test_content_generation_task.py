from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from helix.workers.tasks.content import _generate_async, _build_user_prompt, DescriptionDraft


async def test_generate_async_upserts_draft():
    tenant_id = uuid4()
    product_id = uuid4()

    mock_tenant = MagicMock()
    mock_tenant.id = tenant_id
    mock_tenant.pack_id = "kbeauty"

    mock_product = MagicMock()
    mock_product.id = product_id
    mock_product.title = "Hydrating Toner"
    mock_product.price_minor = 2500
    mock_product.currency = "USD"
    mock_product.categories = ["toner"]
    mock_product.domain_attributes = {"skin_type": "dry"}

    mock_draft_result = DescriptionDraft(html="<p>Great toner</p>")

    mock_session = AsyncMock()
    mock_session.commit = AsyncMock()

    with (
        patch("helix.workers.tasks.content.get_tenant_by_id", new_callable=AsyncMock, return_value=mock_tenant),
        patch("helix.workers.tasks.content.get_product_by_id", new_callable=AsyncMock, return_value=mock_product),
        patch("helix.workers.tasks.content.upsert_content_draft", new_callable=AsyncMock) as mock_upsert,
        patch("helix.workers.tasks.content.get_pack_for_tenant", return_value=MagicMock()),
        patch("helix.workers.tasks.content.LLMGateway") as mock_gw_cls,
        patch("helix.workers.tasks.content.async_session_factory") as mock_factory,
        patch("helix.workers.tasks.content.get_settings", return_value=MagicMock()),
    ):
        mock_gw = AsyncMock()
        mock_gw.complete = AsyncMock(return_value=mock_draft_result)
        mock_gw_cls.return_value = mock_gw

        mock_factory.return_value.__aenter__ = AsyncMock(return_value=mock_session)
        mock_factory.return_value.__aexit__ = AsyncMock(return_value=False)

        await _generate_async(str(tenant_id), str(product_id))

    mock_upsert.assert_called_once_with(
        mock_session, tenant_id, product_id, "description_html", "<p>Great toner</p>"
    )
    mock_session.commit.assert_called_once()


async def test_generate_async_skips_when_product_not_found():
    tenant_id = uuid4()
    product_id = uuid4()

    mock_session = AsyncMock()
    mock_session.commit = AsyncMock()

    with (
        patch("helix.workers.tasks.content.get_tenant_by_id", new_callable=AsyncMock, return_value=MagicMock()),
        patch("helix.workers.tasks.content.get_product_by_id", new_callable=AsyncMock, return_value=None),
        patch("helix.workers.tasks.content.upsert_content_draft", new_callable=AsyncMock) as mock_upsert,
        patch("helix.workers.tasks.content.async_session_factory") as mock_factory,
        patch("helix.workers.tasks.content.get_settings", return_value=MagicMock()),
    ):
        mock_factory.return_value.__aenter__ = AsyncMock(return_value=mock_session)
        mock_factory.return_value.__aexit__ = AsyncMock(return_value=False)

        await _generate_async(str(tenant_id), str(product_id))

    mock_upsert.assert_not_called()


def test_build_user_prompt_includes_title_and_attributes():
    product = MagicMock()
    product.title = "Vitamin C Serum"
    product.price_minor = 3500
    product.currency = "USD"
    product.categories = ["serum"]
    product.domain_attributes = {"ingredients": "ascorbic acid"}

    prompt = _build_user_prompt(product)
    assert "Vitamin C Serum" in prompt
    assert "ascorbic acid" in prompt
