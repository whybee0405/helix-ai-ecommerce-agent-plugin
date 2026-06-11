import pytest
from unittest.mock import MagicMock
from httpx import AsyncClient, ASGITransport
from uuid import uuid4

from helix.api.app import create_app
from helix.db.models import Tenant
from helix.packs import registry
from tests.conftest import make_test_settings


@pytest.fixture
def settings():
    return make_test_settings()


@pytest.fixture
def app(settings):
    return create_app(settings)


@pytest.fixture
def fake_tenant():
    """Create a fake tenant for testing."""
    return Tenant(
        id=uuid4(),
        name="Test Store",
        platform="woocommerce",
        store_url="https://test.example.com",
        credentials_enc=b"enc",
        public_key=uuid4(),
    )


@pytest.fixture
def mock_pack():
    """Create a mock pack with all required attributes."""
    mock_pack = MagicMock()
    mock_pack.id = "kbeauty"
    mock_pack.display_name = "K-Beauty"
    mock_pack.version = "1.0"
    mock_pack.compatibility_rules = [{"ingredients": ["retinol"], "conflicts": ["aha"]}]
    mock_pack.taxonomy = {"cleanser": {}, "toner": {}}
    mock_pack.copy = {"en": {"shipping": "Free shipping"}}
    return mock_pack


@pytest.fixture
def seeded_registry(mock_pack):
    """Seed the registry with mock pack and restore after test."""
    original_registry = registry._registry.copy()
    try:
        registry._registry.clear()
        registry._registry["kbeauty"] = mock_pack
        yield registry._registry
    finally:
        registry._registry.clear()
        registry._registry.update(original_registry)


@pytest.fixture
async def client_with_auth(app, fake_tenant, seeded_registry):
    """Create a client with overridden get_tenant dependency."""
    from helix.api.deps import get_tenant

    # Override the dependency so we skip the real header/DB lookup
    app.dependency_overrides[get_tenant] = lambda: fake_tenant
    try:
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
            yield c
    finally:
        app.dependency_overrides.clear()


@pytest.fixture
async def client_without_auth(app, seeded_registry):
    """Create a client without dependency override (requires real auth)."""
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        yield c


async def test_list_packs_returns_loaded_packs(client_with_auth):
    """Test GET /v1/packs returns list of loaded packs."""
    resp = await client_with_auth.get("/v1/packs")
    assert resp.status_code == 200
    data = resp.json()
    assert isinstance(data, list)
    assert len(data) == 1
    assert data[0]["id"] == "kbeauty"
    assert data[0]["display_name"] == "K-Beauty"
    assert data[0]["version"] == "1.0"


async def test_get_pack_returns_detail(client_with_auth):
    """Test GET /v1/packs/{pack_id} returns detailed pack information."""
    resp = await client_with_auth.get("/v1/packs/kbeauty")
    assert resp.status_code == 200
    data = resp.json()
    assert data["id"] == "kbeauty"
    assert data["display_name"] == "K-Beauty"
    assert data["version"] == "1.0"
    assert data["compatibility_rules_count"] == 1
    assert data["taxonomy_categories"] == ["cleanser", "toner"]
    assert "en" in data["copy_locales"]


async def test_get_pack_404_unknown(client_with_auth):
    """Test GET /v1/packs/{pack_id} returns 404 for unknown pack."""
    resp = await client_with_auth.get("/v1/packs/unknown-pack")
    assert resp.status_code == 404
    data = resp.json()
    assert "detail" in data


async def test_list_packs_requires_auth(client_without_auth):
    """Test GET /v1/packs requires authentication."""
    resp = await client_without_auth.get("/v1/packs")
    assert resp.status_code == 401
