import json
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4

from cryptography.fernet import Fernet

from helix.connectors.writeback import write_back_to_platform
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

    with patch("helix.connectors.writeback.httpx.AsyncClient", return_value=mock_client):
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

    with patch("helix.connectors.writeback.httpx.AsyncClient", return_value=mock_client):
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
