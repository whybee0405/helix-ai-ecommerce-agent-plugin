import pytest
from unittest.mock import MagicMock, patch
from uuid import uuid4


def test_embed_product_calls_voyage():
    from tests.conftest import make_test_settings
    test_settings = make_test_settings()

    # Patch get_settings at eshopeo.config level BEFORE engine.py gets imported by the lazy import
    with patch("eshopeo.config.get_settings", return_value=test_settings):
        from eshopeo.workers.tasks import embedding

        mock_response = MagicMock()
        mock_response.json.return_value = {"data": [{"embedding": [0.1] * 1024}]}
        mock_response.raise_for_status = MagicMock()

        tenant_id = str(uuid4())
        fake_product = MagicMock()
        fake_product.id = uuid4()
        fake_product.tenant_id = tenant_id
        fake_product.title = "Essence"
        fake_product.categories = ["serum"]
        fake_product.domain_attributes = {"skin_types": ["dry"]}

        mock_session = MagicMock()
        mock_session.get.return_value = fake_product
        mock_ctx = MagicMock()
        mock_ctx.__enter__ = MagicMock(return_value=mock_session)
        mock_ctx.__exit__ = MagicMock(return_value=False)

        with patch("eshopeo.workers.tasks.embedding.httpx.post", return_value=mock_response) as mock_post:
            # Override the lazy-imported get_sync_session and get_settings inside _embed_and_store
            import eshopeo.db.engine as eng
            eng_ctx = MagicMock()
            eng_ctx.__enter__ = MagicMock(return_value=mock_session)
            eng_ctx.__exit__ = MagicMock(return_value=False)
            with patch.object(eng, "get_sync_session", return_value=eng_ctx):
                embedding._embed_and_store(tenant_id, str(fake_product.id))

    mock_post.assert_called_once()
    call_kwargs = mock_post.call_args
    assert "voyage-3-lite" in str(call_kwargs)


def test_build_embed_text():
    from eshopeo.workers.tasks.embedding import _build_embed_text
    text = _build_embed_text("Snail Essence", ["serum"], {"skin_types": ["dry"]})
    assert "Snail Essence" in text
    assert "serum" in text
