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
    llm_model_chat: str = "claude-haiku-4-5"  # widget streaming — cost-optimised

    brave_api_key: SecretStr | None = None

    brand_name: str = "helix"
    environment: Literal["development", "production"] = "development"
    log_level: str = "INFO"
    packs_dir: str = "/packs"
    widget_rate_limit: int = 30
    cors_allowed_origins: list[str] = ["*"]
    default_monthly_query_limit: int = 10_000

    # Public base URLs
    api_base_url: str = "https://api.helix.cloudia.co.za"
    app_base_url: str = "https://helix.cloudia.co.za"

    # Paddle billing
    paddle_api_key: SecretStr | None = None
    paddle_webhook_secret: SecretStr | None = None
    paddle_sandbox: bool = False
    # Paddle price IDs — set in .env per environment
    paddle_price_starter_usd: str = ""
    paddle_price_growth_usd: str = ""
    paddle_price_pro_usd: str = ""
    paddle_price_starter_zar: str = ""
    paddle_price_growth_zar: str = ""
    paddle_price_pro_zar: str = ""

    # Resend transactional email
    resend_api_key: SecretStr | None = None

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
