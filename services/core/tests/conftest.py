from cryptography.fernet import Fernet
from eshopeo.config import Settings, get_settings

# Fixed test encryption key so all tests share the same Fernet key
_TEST_FERNET_KEY = Fernet.generate_key().decode()

_TEST_SETTINGS = None


def make_test_settings(**overrides) -> Settings:
    base = dict(
        database_url="postgresql://eshopeo:eshopeo@localhost:5432/eshopeo_test",
        redis_url="redis://localhost:6379/1",
        anthropic_api_key="sk-ant-test",
        voyage_api_key="pa-test",
        credential_encryption_key=_TEST_FERNET_KEY,
        session_secret="test-secret-key-that-is-32-chars!!",
        provision_key="test-provision-key",
        brand_name="TestBrand",
    )
    base.update(overrides)
    return Settings(**base)


def _seed_settings_cache() -> None:
    """Populate eshopeo.config.get_settings so that any module-level
    get_settings() call (e.g. engine.py lazy init) uses stable test values."""
    import eshopeo.config as _cfg
    global _TEST_SETTINGS
    _TEST_SETTINGS = make_test_settings()
    # Replace the cached function with a simple lambda that returns test settings
    _cfg.get_settings = lambda: _TEST_SETTINGS  # type: ignore[assignment]


# Seed the settings cache once when the conftest is loaded
_seed_settings_cache()
