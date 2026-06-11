from cryptography.fernet import Fernet
from helix.config import Settings


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
