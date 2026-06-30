import pytest
from cryptography.fernet import Fernet
from eshopeo.config import Settings


def make_settings(**overrides) -> Settings:
    base = dict(
        database_url="postgresql://u:p@localhost/db",
        redis_url="redis://localhost:6379/0",
        anthropic_api_key="sk-ant-test",
        voyage_api_key="pa-test",
        credential_encryption_key=Fernet.generate_key().decode(),
        session_secret="a" * 32,
        provision_key="test-provision",
        brand_name="TestBrand",
    )
    base.update(overrides)
    return Settings(**base)


def test_database_url_async():
    s = make_settings()
    assert "asyncpg" in s.database_url_async


def test_database_url_sync():
    s = make_settings()
    assert "psycopg2" in s.database_url_sync


def test_model_ids_default():
    s = make_settings()
    assert s.llm_model_classify == "claude-haiku-4-5"
    assert s.llm_model_generate == "claude-sonnet-4-6"
    assert s.llm_model_reason == "claude-opus-4-8"


def test_missing_required_field_raises():
    with pytest.raises(Exception):
        Settings(database_url="postgresql://u:p@localhost/db")
