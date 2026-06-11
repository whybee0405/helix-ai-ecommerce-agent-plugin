from cryptography.fernet import Fernet
from helix.config import Settings, get_settings


def make_test_settings(**overrides) -> Settings:
    base = dict(
        database_url="postgresql://helix:helix@localhost:5432/helix_test",
        redis_url="redis://localhost:6379/1",
        anthropic_api_key="sk-ant-test",
        voyage_api_key="pa-test",
        credential_encryption_key=Fernet.generate_key().decode(),
        session_secret="test-secret-key-that-is-32-chars!!",
        provision_key="test-provision-key",
        brand_name="TestBrand",
    )
    base.update(overrides)
    return Settings(**base)


def _seed_settings_cache() -> None:
    """Populate the get_settings() lru_cache with test values so engine.py
    lazy initialisation never tries to build Settings from environment."""
    import helix.config as _cfg
    _cfg.get_settings.cache_clear()
    test_settings = make_test_settings()
    # Warm the cache by calling the original but wrapping it temporarily
    from unittest.mock import patch
    with patch.object(_cfg, "get_settings", return_value=test_settings):
        pass
    # Direct cache population: replace cache_info sentinel
    # Simplest approach: monkey-patch at module level for test session
    _cfg.get_settings = lambda: test_settings  # type: ignore[assignment]


# Seed the settings cache once when the conftest is loaded, so that any
# module-level `get_settings()` call (e.g. engine.py lazy init) uses test values.
_seed_settings_cache()
