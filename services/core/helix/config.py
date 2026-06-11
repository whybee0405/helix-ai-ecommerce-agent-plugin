from functools import lru_cache
from typing import Literal

from pydantic import PostgresDsn, RedisDsn, SecretStr
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env", env_file_encoding="utf-8", extra="ignore"
    )

    database_url: PostgresDsn
    redis_url: RedisDsn
    anthropic_api_key: SecretStr
    voyage_api_key: SecretStr
    credential_encryption_key: SecretStr
    session_secret: SecretStr
    provision_key: SecretStr

    llm_model_classify: str = "claude-haiku-4-5"
    llm_model_generate: str = "claude-sonnet-4-6"
    llm_model_reason: str = "claude-opus-4-8"

    brand_name: str = "helix"
    environment: Literal["development", "production"] = "development"
    log_level: str = "INFO"
    packs_dir: str = "/packs"

    @property
    def database_url_async(self) -> str:
        return str(self.database_url).replace(
            "postgresql://", "postgresql+asyncpg://", 1
        )

    @property
    def database_url_sync(self) -> str:
        return str(self.database_url).replace(
            "postgresql://", "postgresql+psycopg2://", 1
        )


@lru_cache
def get_settings() -> Settings:
    return Settings()
