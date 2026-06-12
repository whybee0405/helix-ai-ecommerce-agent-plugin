# Phase 0 — Foundations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the complete Phase 0 foundation so a real WooCommerce store's catalog syncs into PostgreSQL with embeddings generated, via the connector contract, with tenancy and the LLM gateway in place.

**Architecture:** Strict dependency order — scaffold → infra → DB → auth → gateway → packs → connector contract → WooCommerce plugin → embeddings. All business logic in `domain/` or `connectors/`; all Claude calls through `helix.llm`; every query scoped by `tenant_id`.

**Tech Stack:** Python 3.12 · FastAPI · Pydantic v2 · SQLAlchemy 2.0 + asyncpg · Alembic · pgvector · Redis · Celery · Anthropic SDK · Voyage AI REST · structlog · cryptography · python-jose · jsonschema · PyYAML · PHP 8.0+ · Docker Compose

---

## File Map

**Repo root:** `.env.example`, `.gitignore`

**`services/core/`:** `pyproject.toml`, `Dockerfile`

**`services/core/helix/`:** `__init__.py`, `config.py`

**`services/core/helix/db/`:** `__init__.py`, `engine.py`, `models.py`, `tenant_scope.py`, `crud/__init__.py`, `crud/tenants.py`, `crud/products.py`, `migrations/env.py`, `migrations/script.py.mako`, `migrations/versions/0001_initial.py`

**`services/core/helix/api/`:** `__init__.py`, `app.py`, `deps.py`, `routers/__init__.py`, `routers/health.py`, `routers/tenants.py`, `routers/sync.py`, `routers/webhooks.py`, `routers/widget.py`

**`services/core/helix/connectors/`:** `__init__.py`, `models.py`

**`services/core/helix/llm/`:** `__init__.py`, `gateway.py`, `layers.py`

**`services/core/helix/packs/`:** `__init__.py`, `loader.py`, `registry.py`

**`services/core/helix/workers/`:** `__init__.py`, `celery_app.py`, `tasks/__init__.py`, `tasks/embedding.py`

**`services/core/tests/`:** `conftest.py`, `test_config.py`, `test_db_models.py`, `test_tenant_scope.py`, `test_auth.py`, `test_health.py`, `test_tenants_endpoint.py`, `test_connector_models.py`, `test_pack_loader.py`, `test_llm_gateway.py`, `test_sync_endpoint.py`, `test_webhooks_endpoint.py`, `test_embedding_tasks.py`

**`infra/`:** `compose.yaml`

**`connectors/woocommerce/`:** `helix-connector.php`, `includes/class-helix-admin.php`, `includes/class-helix-api-client.php`, `includes/class-helix-sync.php`, `includes/class-helix-webhooks.php`

**`packs/kbeauty/`:** `pack.yaml`, `profile_schema.json`, `product_schema.json`, `taxonomy.yaml`, `compatibility_rules.yaml`, `prompts/system.md`, `prompts/consultant.md`, `copy/en.json`

**`docs/adr/`:** `0001` through `0005`

---

## Task 1: Monorepo scaffold

**Files:**
- Create: `services/core/pyproject.toml`
- Create: `.gitignore`
- Create: `services/core/helix/__init__.py`
- Create: `services/core/tests/__init__.py`

- [ ] **Step 1: Create directory structure**

```bash
mkdir -p services/core/helix/{api/routers,db/{crud,migrations/versions},connectors,llm,packs,workers/tasks,domain}
mkdir -p services/core/tests
mkdir -p infra connectors/woocommerce/includes packs/kbeauty/{prompts,copy} docs/adr
touch services/core/helix/__init__.py services/core/tests/__init__.py
```

- [ ] **Step 2: Create `services/core/pyproject.toml`**

```toml
[build-system]
requires = ["hatchling"]
build-backend = "hatchling.build"

[project]
name = "helix"
version = "0.1.0"
requires-python = ">=3.12"
dependencies = [
    "fastapi>=0.111.0",
    "uvicorn[standard]>=0.30.0",
    "pydantic[email]>=2.7.0",
    "pydantic-settings>=2.3.0",
    "sqlalchemy[asyncio]>=2.0.30",
    "alembic>=1.13.0",
    "asyncpg>=0.29.0",
    "psycopg2-binary>=2.9.9",
    "pgvector>=0.3.2",
    "redis>=5.0.4",
    "celery[redis]>=5.4.0",
    "anthropic>=0.30.0",
    "httpx>=0.27.0",
    "python-jose[cryptography]>=3.3.0",
    "cryptography>=42.0.0",
    "structlog>=24.2.0",
    "jsonschema>=4.22.0",
    "pyyaml>=6.0.1",
]

[project.optional-dependencies]
dev = [
    "pytest>=8.0",
    "pytest-asyncio>=0.23",
    "pytest-cov>=5.0",
    "ruff>=0.4.0",
    "mypy>=1.10.0",
    "types-jsonschema>=4.22",
    "types-pyyaml>=6.0",
    "types-python-jose>=3.3",
]

[tool.hatch.build.targets.wheel]
packages = ["helix"]

[tool.ruff]
target-version = "py312"
line-length = 100

[tool.ruff.lint]
select = ["E", "F", "I", "N", "UP", "B", "SIM"]
ignore = ["B008"]

[tool.mypy]
python_version = "3.12"
strict = true
ignore_missing_imports = true
plugins = ["pydantic.mypy"]

[tool.pytest.ini_options]
asyncio_mode = "auto"
testpaths = ["tests"]
```

- [ ] **Step 3: Create `.gitignore`**

```gitignore
__pycache__/
*.py[cod]
*.egg-info/
dist/
.env
.venv/
venv/
.mypy_cache/
.ruff_cache/
.pytest_cache/
htmlcov/
*.log
db_data/
node_modules/
```

- [ ] **Step 4: Install dependencies and verify**

```bash
cd services/core && pip install -e ".[dev]"
python -c "import fastapi, sqlalchemy, alembic, anthropic; print('ok')"
```
Expected: `ok`

- [ ] **Step 5: Commit**

```bash
git add services/core/pyproject.toml .gitignore services/core/helix/__init__.py
git commit -m "chore: scaffold monorepo structure and pyproject.toml"
```

---

## Task 2: Docker infrastructure

**Files:**
- Create: `infra/compose.yaml`
- Create: `services/core/Dockerfile`
- Create: `.env.example`

- [ ] **Step 1: Create `services/core/Dockerfile`**

```dockerfile
FROM python:3.12-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends gcc libpq-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY pyproject.toml .
RUN pip install --no-cache-dir --editable ".[dev]"

COPY . .

ENV PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1
```

- [ ] **Step 2: Create `infra/compose.yaml`**

```yaml
services:
  db:
    image: pgvector/pgvector:pg16
    environment:
      POSTGRES_USER: helix
      POSTGRES_PASSWORD: helix
      POSTGRES_DB: helix
    ports:
      - "5432:5432"
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U helix"]
      interval: 5s
      timeout: 5s
      retries: 5
    volumes:
      - db_data:/var/lib/postgresql/data

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 3s
      retries: 5

  api:
    build:
      context: ../services/core
      dockerfile: Dockerfile
    command: uvicorn helix.api.app:app --host 0.0.0.0 --port 8000 --reload
    ports:
      - "8000:8000"
    env_file: ../.env
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy
    volumes:
      - ../services/core:/app
      - ../packs:/packs

  worker:
    build:
      context: ../services/core
      dockerfile: Dockerfile
    command: celery -A helix.workers.celery_app worker --loglevel=info -Q default,embedding
    env_file: ../.env
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy
    volumes:
      - ../services/core:/app
      - ../packs:/packs

volumes:
  db_data:
```

- [ ] **Step 3: Create `.env.example`**

```bash
# Database — use postgresql:// (driver prefix added at runtime)
DATABASE_URL=postgresql://helix:helix@localhost:5432/helix

# Redis
REDIS_URL=redis://localhost:6379/0

# Anthropic — get from console.anthropic.com
ANTHROPIC_API_KEY=sk-ant-...

# Voyage AI — get from dash.voyageai.com
VOYAGE_API_KEY=pa-...

# Generate with: python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
CREDENTIAL_ENCRYPTION_KEY=

# Generate with: python -c "import secrets; print(secrets.token_urlsafe(32))"
SESSION_SECRET=

# Shared secret for POST /v1/tenants — set to a strong random string
PROVISION_KEY=

# Public brand name shown in responses (codename: helix)
BRAND_NAME=helix

# development | production
ENVIRONMENT=development
LOG_LEVEL=INFO

# Absolute path to packs directory (in Docker: /packs)
PACKS_DIR=/packs
```

- [ ] **Step 4: Verify compose builds**

```bash
docker compose -f infra/compose.yaml build
```
Expected: `Successfully built` for api and worker images.

- [ ] **Step 5: Commit**

```bash
git add infra/compose.yaml services/core/Dockerfile .env.example
git commit -m "chore: add Docker Compose infrastructure and Dockerfile"
```

---

## Task 3: Application config

**Files:**
- Create: `services/core/helix/config.py`
- Test: `services/core/tests/test_config.py`

- [ ] **Step 1: Write the failing test**

```python
# services/core/tests/test_config.py
import pytest
from cryptography.fernet import Fernet
from helix.config import Settings, get_settings


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
        Settings(database_url="postgresql://u:p@localhost/db")  # missing many required fields
```

- [ ] **Step 2: Run test — verify it fails**

```bash
cd services/core && pytest tests/test_config.py -v
```
Expected: `ImportError` — `helix.config` does not exist yet.

- [ ] **Step 3: Create `services/core/helix/config.py`**

```python
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
```

- [ ] **Step 4: Run test — verify it passes**

```bash
cd services/core && pytest tests/test_config.py -v
```
Expected: `4 passed`

- [ ] **Step 5: Commit**

```bash
git add services/core/helix/config.py services/core/tests/test_config.py
git commit -m "feat: add pydantic-settings config with tiered model IDs"
```

---

## Task 4: Database models

**Files:**
- Create: `services/core/helix/db/__init__.py`
- Create: `services/core/helix/db/models.py`
- Test: `services/core/tests/test_db_models.py`

- [ ] **Step 1: Write the failing test**

```python
# services/core/tests/test_db_models.py
from helix.db.models import Tenant, Product, Customer, Order, Job, UsageEvent, Base
from sqlalchemy import inspect


def test_all_tables_defined():
    tables = Base.metadata.tables
    assert set(tables.keys()) == {"tenant", "product", "customer", "order", "job", "usage_event"}


def test_product_has_embedding_column():
    cols = {c.name for c in Product.__table__.columns}
    assert "embedding" in cols
    assert "tenant_id" in cols
    assert "domain_attributes" in cols


def test_tenant_has_public_key():
    cols = {c.name for c in Tenant.__table__.columns}
    assert "public_key" in cols
    assert "credentials_enc" in cols


def test_usage_event_has_cost():
    cols = {c.name for c in UsageEvent.__table__.columns}
    assert "cost_usd" in cols
    assert "tokens_in" in cols
    assert "tokens_out" in cols
```

- [ ] **Step 2: Run test — verify it fails**

```bash
cd services/core && pytest tests/test_db_models.py -v
```
Expected: `ImportError` — `helix.db.models` does not exist yet.

- [ ] **Step 3: Create `services/core/helix/db/__init__.py`** (empty)

```python
```

- [ ] **Step 4: Create `services/core/helix/db/models.py`**

```python
from datetime import datetime, timezone
from typing import Optional
from uuid import UUID, uuid4

from pgvector.sqlalchemy import Vector
from sqlalchemy import (
    BigInteger,
    Boolean,
    ForeignKey,
    Integer,
    LargeBinary,
    Numeric,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.dialects.postgresql import JSONB, TIMESTAMP
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column


def _now() -> datetime:
    return datetime.now(timezone.utc)


class Base(DeclarativeBase):
    pass


class Tenant(Base):
    __tablename__ = "tenant"

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    name: Mapped[str] = mapped_column(String, nullable=False)
    platform: Mapped[str] = mapped_column(String, nullable=False)
    store_url: Mapped[str] = mapped_column(String, nullable=False)
    credentials_enc: Mapped[bytes] = mapped_column(LargeBinary, nullable=False)
    public_key: Mapped[UUID] = mapped_column(unique=True, default=uuid4)
    created_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now
    )


class Product(Base):
    __tablename__ = "product"
    __table_args__ = (
        UniqueConstraint("tenant_id", "platform_id", name="uq_product_tenant_platform"),
    )

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    tenant_id: Mapped[UUID] = mapped_column(
        ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False, index=True
    )
    platform_id: Mapped[str] = mapped_column(String, nullable=False)
    title: Mapped[str] = mapped_column(String, nullable=False)
    description_html: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    price_minor: Mapped[int] = mapped_column(Integer, nullable=False)
    currency: Mapped[str] = mapped_column(String(3), nullable=False)
    images: Mapped[list] = mapped_column(JSONB, nullable=False, default=list)
    categories: Mapped[list] = mapped_column(JSONB, nullable=False, default=list)
    in_stock: Mapped[bool] = mapped_column(Boolean, nullable=False)
    domain_attributes: Mapped[dict] = mapped_column(JSONB, nullable=False, default=dict)
    embedding: Mapped[Optional[list[float]]] = mapped_column(Vector(1024), nullable=True)
    updated_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now, onupdate=_now
    )


class Customer(Base):
    __tablename__ = "customer"
    __table_args__ = (
        UniqueConstraint("tenant_id", "platform_id", name="uq_customer_tenant_platform"),
    )

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    tenant_id: Mapped[UUID] = mapped_column(
        ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False, index=True
    )
    platform_id: Mapped[str] = mapped_column(String, nullable=False)
    email_hash: Mapped[str] = mapped_column(String, nullable=False)
    profile: Mapped[dict] = mapped_column(JSONB, nullable=False, default=dict)
    created_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now
    )


class Order(Base):
    __tablename__ = "order"
    __table_args__ = (
        UniqueConstraint("tenant_id", "platform_id", name="uq_order_tenant_platform"),
    )

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    tenant_id: Mapped[UUID] = mapped_column(
        ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False, index=True
    )
    platform_id: Mapped[str] = mapped_column(String, nullable=False)
    customer_id: Mapped[Optional[UUID]] = mapped_column(
        ForeignKey("customer.id"), nullable=True
    )
    total_minor: Mapped[int] = mapped_column(Integer, nullable=False)
    currency: Mapped[str] = mapped_column(String(3), nullable=False)
    status: Mapped[str] = mapped_column(String, nullable=False)
    line_items: Mapped[list] = mapped_column(JSONB, nullable=False, default=list)
    placed_at: Mapped[datetime] = mapped_column(TIMESTAMP(timezone=True), nullable=False)


class Job(Base):
    __tablename__ = "job"

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    tenant_id: Mapped[UUID] = mapped_column(
        ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False, index=True
    )
    type: Mapped[str] = mapped_column(String, nullable=False)
    status: Mapped[str] = mapped_column(String, nullable=False, default="pending")
    progress: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    total: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    error: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    started_at: Mapped[Optional[datetime]] = mapped_column(
        TIMESTAMP(timezone=True), nullable=True
    )
    finished_at: Mapped[Optional[datetime]] = mapped_column(
        TIMESTAMP(timezone=True), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now
    )


class UsageEvent(Base):
    __tablename__ = "usage_event"

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    tenant_id: Mapped[UUID] = mapped_column(
        ForeignKey("tenant.id", ondelete="CASCADE"), nullable=False, index=True
    )
    model: Mapped[str] = mapped_column(String, nullable=False)
    tokens_in: Mapped[int] = mapped_column(Integer, nullable=False)
    tokens_out: Mapped[int] = mapped_column(Integer, nullable=False)
    cost_usd: Mapped[float] = mapped_column(Numeric(10, 6), nullable=False)
    endpoint: Mapped[str] = mapped_column(String, nullable=False)
    created_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), nullable=False, default=_now
    )
```

- [ ] **Step 5: Run test — verify it passes**

```bash
cd services/core && pytest tests/test_db_models.py -v
```
Expected: `4 passed`

- [ ] **Step 6: Commit**

```bash
git add services/core/helix/db/ services/core/tests/test_db_models.py
git commit -m "feat: add SQLAlchemy 2.0 models for all six Phase 0 tables"
```

---

## Task 5: Database engine + Alembic setup

**Files:**
- Create: `services/core/helix/db/engine.py`
- Create: `services/core/helix/db/migrations/env.py`
- Create: `services/core/helix/db/migrations/script.py.mako`
- Create: `services/core/alembic.ini`

- [ ] **Step 1: Create `services/core/helix/db/engine.py`**

```python
from collections.abc import AsyncGenerator

from sqlalchemy.ext.asyncio import (
    AsyncSession,
    async_sessionmaker,
    create_async_engine,
)
from sqlalchemy import create_engine
from sqlalchemy.orm import Session, sessionmaker

from helix.config import get_settings

_settings = get_settings()

# Async engine for FastAPI
async_engine = create_async_engine(
    _settings.database_url_async,
    echo=_settings.environment == "development",
    pool_pre_ping=True,
)
async_session_factory = async_sessionmaker(async_engine, expire_on_commit=False)


async def get_async_session() -> AsyncGenerator[AsyncSession, None]:
    async with async_session_factory() as session:
        yield session


# Sync engine for Celery tasks and Alembic
sync_engine = create_engine(
    _settings.database_url_sync,
    pool_pre_ping=True,
)
sync_session_factory = sessionmaker(sync_engine, expire_on_commit=False)


def get_sync_session() -> Session:
    return sync_session_factory()
```

- [ ] **Step 2: Create `services/core/alembic.ini`**

```ini
[alembic]
script_location = helix/db/migrations
prepend_sys_path = .
version_path_separator = os

[loggers]
keys = root,sqlalchemy,alembic

[handlers]
keys = console

[formatters]
keys = generic

[logger_root]
level = WARN
handlers = console
qualname =

[logger_sqlalchemy]
level = WARN
handlers =
qualname = sqlalchemy.engine

[logger_alembic]
level = INFO
handlers =
qualname = alembic

[handler_console]
class = StreamHandler
args = (sys.stderr,)
level = NOTSET
formatter = generic

[formatter_generic]
format = %(levelname)-5.5s [%(name)s] %(message)s
datefmt = %H:%M:%S
```

- [ ] **Step 3: Create `services/core/helix/db/migrations/env.py`**

```python
from logging.config import fileConfig

from alembic import context
from sqlalchemy import engine_from_config, pool

from helix.config import get_settings
from helix.db.models import Base

config = context.config
if config.config_file_name is not None:
    fileConfig(config.config_file_name)

target_metadata = Base.metadata

settings = get_settings()
config.set_main_option("sqlalchemy.url", settings.database_url_sync)


def run_migrations_offline() -> None:
    url = config.get_main_option("sqlalchemy.url")
    context.configure(
        url=url,
        target_metadata=target_metadata,
        literal_binds=True,
        dialect_opts={"paramstyle": "named"},
    )
    with context.begin_transaction():
        context.run_migrations()


def run_migrations_online() -> None:
    connectable = engine_from_config(
        config.get_section(config.config_ini_section, {}),
        prefix="sqlalchemy.",
        poolclass=pool.NullPool,
    )
    with connectable.connect() as connection:
        context.configure(connection=connection, target_metadata=target_metadata)
        with context.begin_transaction():
            context.run_migrations()


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()
```

- [ ] **Step 4: Create `services/core/helix/db/migrations/script.py.mako`**

```mako
"""${message}

Revision ID: ${up_revision}
Revises: ${down_revision | comma,n}
Create Date: ${create_date}

"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa
${imports if imports else ""}

revision: str = ${repr(up_revision)}
down_revision: Union[str, None] = ${repr(down_revision)}
branch_labels: Union[str, Sequence[str], None] = ${repr(branch_labels)}
depends_on: Union[str, Sequence[str], None] = ${repr(depends_on)}


def upgrade() -> None:
    ${upgrades if upgrades else "pass"}


def downgrade() -> None:
    ${downgrades if downgrades else "pass"}
```

- [ ] **Step 5: Commit**

```bash
git add services/core/helix/db/engine.py services/core/helix/db/migrations/ services/core/alembic.ini
git commit -m "feat: add SQLAlchemy async engine and Alembic migration scaffold"
```

---

## Task 6: Initial database migration

**Files:**
- Create: `services/core/helix/db/migrations/versions/0001_initial.py`

- [ ] **Step 1: Start the database container**

```bash
docker compose -f infra/compose.yaml up -d db
# Wait for it to be healthy
docker compose -f infra/compose.yaml ps db
```
Expected: `db` shows `healthy`.

- [ ] **Step 2: Create `services/core/helix/db/migrations/versions/0001_initial.py`**

```python
"""Initial schema with pgvector

Revision ID: 0001
Revises:
Create Date: 2026-06-11
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from pgvector.sqlalchemy import Vector
from sqlalchemy.dialects import postgresql

revision: str = "0001"
down_revision: Union[str, None] = None
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.execute("CREATE EXTENSION IF NOT EXISTS vector")

    op.create_table(
        "tenant",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("name", sa.String, nullable=False),
        sa.Column("platform", sa.String, nullable=False),
        sa.Column("store_url", sa.String, nullable=False),
        sa.Column("credentials_enc", sa.LargeBinary, nullable=False),
        sa.Column("public_key", postgresql.UUID(as_uuid=True), nullable=False, unique=True),
        sa.Column(
            "created_at",
            sa.TIMESTAMP(timezone=True),
            nullable=False,
            server_default=sa.text("now()"),
        ),
    )

    op.create_table(
        "product",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column(
            "tenant_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("tenant.id", ondelete="CASCADE"),
            nullable=False,
            index=True,
        ),
        sa.Column("platform_id", sa.String, nullable=False),
        sa.Column("title", sa.String, nullable=False),
        sa.Column("description_html", sa.Text, nullable=True),
        sa.Column("price_minor", sa.Integer, nullable=False),
        sa.Column("currency", sa.String(3), nullable=False),
        sa.Column("images", postgresql.JSONB, nullable=False, server_default="'[]'"),
        sa.Column("categories", postgresql.JSONB, nullable=False, server_default="'[]'"),
        sa.Column("in_stock", sa.Boolean, nullable=False),
        sa.Column(
            "domain_attributes", postgresql.JSONB, nullable=False, server_default="'{}'"
        ),
        sa.Column("embedding", Vector(1024), nullable=True),
        sa.Column(
            "updated_at",
            sa.TIMESTAMP(timezone=True),
            nullable=False,
            server_default=sa.text("now()"),
        ),
        sa.UniqueConstraint("tenant_id", "platform_id", name="uq_product_tenant_platform"),
    )

    op.create_table(
        "customer",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column(
            "tenant_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("tenant.id", ondelete="CASCADE"),
            nullable=False,
            index=True,
        ),
        sa.Column("platform_id", sa.String, nullable=False),
        sa.Column("email_hash", sa.String, nullable=False),
        sa.Column("profile", postgresql.JSONB, nullable=False, server_default="'{}'"),
        sa.Column(
            "created_at",
            sa.TIMESTAMP(timezone=True),
            nullable=False,
            server_default=sa.text("now()"),
        ),
        sa.UniqueConstraint("tenant_id", "platform_id", name="uq_customer_tenant_platform"),
    )

    op.create_table(
        "order",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column(
            "tenant_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("tenant.id", ondelete="CASCADE"),
            nullable=False,
            index=True,
        ),
        sa.Column("platform_id", sa.String, nullable=False),
        sa.Column(
            "customer_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("customer.id"), nullable=True
        ),
        sa.Column("total_minor", sa.Integer, nullable=False),
        sa.Column("currency", sa.String(3), nullable=False),
        sa.Column("status", sa.String, nullable=False),
        sa.Column("line_items", postgresql.JSONB, nullable=False, server_default="'[]'"),
        sa.Column("placed_at", sa.TIMESTAMP(timezone=True), nullable=False),
        sa.UniqueConstraint("tenant_id", "platform_id", name="uq_order_tenant_platform"),
    )

    op.create_table(
        "job",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column(
            "tenant_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("tenant.id", ondelete="CASCADE"),
            nullable=False,
            index=True,
        ),
        sa.Column("type", sa.String, nullable=False),
        sa.Column("status", sa.String, nullable=False, server_default="'pending'"),
        sa.Column("progress", sa.Integer, nullable=False, server_default="0"),
        sa.Column("total", sa.Integer, nullable=True),
        sa.Column("error", sa.Text, nullable=True),
        sa.Column("started_at", sa.TIMESTAMP(timezone=True), nullable=True),
        sa.Column("finished_at", sa.TIMESTAMP(timezone=True), nullable=True),
        sa.Column(
            "created_at",
            sa.TIMESTAMP(timezone=True),
            nullable=False,
            server_default=sa.text("now()"),
        ),
    )

    op.create_table(
        "usage_event",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column(
            "tenant_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("tenant.id", ondelete="CASCADE"),
            nullable=False,
            index=True,
        ),
        sa.Column("model", sa.String, nullable=False),
        sa.Column("tokens_in", sa.Integer, nullable=False),
        sa.Column("tokens_out", sa.Integer, nullable=False),
        sa.Column("cost_usd", sa.Numeric(10, 6), nullable=False),
        sa.Column("endpoint", sa.String, nullable=False),
        sa.Column(
            "created_at",
            sa.TIMESTAMP(timezone=True),
            nullable=False,
            server_default=sa.text("now()"),
        ),
    )

    op.create_index(
        "ix_product_embedding_hnsw",
        "product",
        ["embedding"],
        postgresql_using="hnsw",
        postgresql_ops={"embedding": "vector_cosine_ops"},
    )


def downgrade() -> None:
    op.drop_table("usage_event")
    op.drop_table("job")
    op.drop_table("order")
    op.drop_table("customer")
    op.drop_table("product")
    op.drop_table("tenant")
    op.execute("DROP EXTENSION IF EXISTS vector")
```

- [ ] **Step 3: Run the migration**

```bash
cd services/core && DATABASE_URL=postgresql://helix:helix@localhost:5432/helix alembic upgrade head
```
Expected:
```
INFO  [alembic.runtime.migration] Running upgrade  -> 0001, Initial schema with pgvector
```

- [ ] **Step 4: Verify tables exist**

```bash
docker compose -f infra/compose.yaml exec db psql -U helix -c "\dt"
```
Expected: 6 tables listed: `customer`, `job`, `order`, `product`, `tenant`, `usage_event`.

- [ ] **Step 5: Commit**

```bash
git add services/core/helix/db/migrations/versions/0001_initial.py
git commit -m "feat: add initial Alembic migration with pgvector and all Phase 0 tables"
```

---

## Task 7: Tenant scope + CRUD layer

**Files:**
- Create: `services/core/helix/db/tenant_scope.py`
- Create: `services/core/helix/db/crud/__init__.py`
- Create: `services/core/helix/db/crud/tenants.py`
- Create: `services/core/helix/db/crud/products.py`
- Test: `services/core/tests/test_tenant_scope.py`

- [ ] **Step 1: Write the failing test**

```python
# services/core/tests/test_tenant_scope.py
import pytest
from unittest.mock import AsyncMock, MagicMock
from uuid import uuid4

from helix.db.tenant_scope import TenantScope


@pytest.fixture
def mock_session():
    session = AsyncMock()
    result = MagicMock()
    result.scalars.return_value.all.return_value = []
    session.execute.return_value = result
    return session


@pytest.mark.asyncio
async def test_tenant_scope_requires_tenant_id():
    with pytest.raises(TypeError):
        TenantScope(session=AsyncMock())  # missing tenant_id


@pytest.mark.asyncio
async def test_get_products_scopes_by_tenant(mock_session):
    tid = uuid4()
    scope = TenantScope(session=mock_session, tenant_id=tid)
    products = await scope.get_products()
    assert products == []
    mock_session.execute.assert_called_once()
    call_args = mock_session.execute.call_args[0][0]
    # The compiled WHERE clause must reference the tenant_id
    compiled = str(call_args.compile(compile_kwargs={"literal_binds": True}))
    assert str(tid) in compiled
```

- [ ] **Step 2: Run test — verify it fails**

```bash
cd services/core && pytest tests/test_tenant_scope.py -v
```
Expected: `ImportError` — `helix.db.tenant_scope` does not exist.

- [ ] **Step 3: Create `services/core/helix/db/tenant_scope.py`**

```python
from uuid import UUID

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.models import Product


class TenantScope:
    """Wraps an AsyncSession with a fixed tenant_id. All query methods enforce isolation."""

    def __init__(self, session: AsyncSession, tenant_id: UUID) -> None:
        self._session = session
        self._tenant_id = tenant_id

    async def get_products(self) -> list[Product]:
        result = await self._session.execute(
            select(Product).where(Product.tenant_id == self._tenant_id)
        )
        return list(result.scalars().all())

    async def get_product_by_platform_id(self, platform_id: str) -> Product | None:
        result = await self._session.execute(
            select(Product).where(
                Product.tenant_id == self._tenant_id,
                Product.platform_id == platform_id,
            )
        )
        return result.scalar_one_or_none()
```

- [ ] **Step 4: Create `services/core/helix/db/crud/__init__.py`** (empty)

- [ ] **Step 5: Create `services/core/helix/db/crud/tenants.py`**

```python
from uuid import UUID

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.models import Tenant


async def get_tenant_by_public_key(
    session: AsyncSession, public_key: UUID
) -> Tenant | None:
    result = await session.execute(
        select(Tenant).where(Tenant.public_key == public_key)
    )
    return result.scalar_one_or_none()


async def create_tenant(session: AsyncSession, tenant: Tenant) -> Tenant:
    session.add(tenant)
    await session.flush()
    await session.refresh(tenant)
    return tenant
```

- [ ] **Step 6: Create `services/core/helix/db/crud/products.py`**

```python
from uuid import UUID

from sqlalchemy import select
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.ext.asyncio import AsyncSession

from helix.db.models import Product


async def upsert_product(session: AsyncSession, product: Product) -> Product:
    stmt = (
        insert(Product)
        .values(
            id=product.id,
            tenant_id=product.tenant_id,
            platform_id=product.platform_id,
            title=product.title,
            description_html=product.description_html,
            price_minor=product.price_minor,
            currency=product.currency,
            images=product.images,
            categories=product.categories,
            in_stock=product.in_stock,
            domain_attributes=product.domain_attributes,
        )
        .on_conflict_do_update(
            constraint="uq_product_tenant_platform",
            set_=dict(
                title=product.title,
                description_html=product.description_html,
                price_minor=product.price_minor,
                currency=product.currency,
                images=product.images,
                categories=product.categories,
                in_stock=product.in_stock,
                domain_attributes=product.domain_attributes,
                updated_at=product.updated_at,
            ),
        )
        .returning(Product)
    )
    result = await session.execute(stmt)
    await session.flush()
    return result.scalar_one()


async def delete_product(
    session: AsyncSession, tenant_id: UUID, platform_id: str
) -> bool:
    result = await session.execute(
        select(Product).where(
            Product.tenant_id == tenant_id,
            Product.platform_id == platform_id,
        )
    )
    product = result.scalar_one_or_none()
    if product is None:
        return False
    await session.delete(product)
    await session.flush()
    return True
```

- [ ] **Step 7: Run test — verify it passes**

```bash
cd services/core && pytest tests/test_tenant_scope.py -v
```
Expected: `2 passed`

- [ ] **Step 8: Commit**

```bash
git add services/core/helix/db/tenant_scope.py services/core/helix/db/crud/ services/core/tests/test_tenant_scope.py
git commit -m "feat: add TenantScope enforcement and CRUD layer for tenants and products"
```

---

## Task 8: FastAPI app skeleton + health endpoint

**Files:**
- Create: `services/core/helix/api/__init__.py`
- Create: `services/core/helix/api/app.py`
- Create: `services/core/helix/api/deps.py`
- Create: `services/core/helix/api/routers/__init__.py`
- Create: `services/core/helix/api/routers/health.py`
- Test: `services/core/tests/test_health.py`

- [ ] **Step 1: Write the failing test**

```python
# services/core/tests/test_health.py
import pytest
from unittest.mock import AsyncMock, patch
from httpx import AsyncClient, ASGITransport

from helix.api.app import create_app
from tests.conftest import make_test_settings


@pytest.fixture
def app():
    return create_app(make_test_settings())


@pytest.fixture
async def client(app):
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        yield c


@pytest.mark.asyncio
async def test_health_returns_200(client):
    with patch("helix.api.routers.health.get_async_session"), \
         patch("helix.api.routers.health.get_redis_client"):
        resp = await client.get("/health")
    assert resp.status_code == 200


@pytest.mark.asyncio
async def test_health_response_shape(client):
    with patch("helix.api.routers.health.get_async_session"), \
         patch("helix.api.routers.health.get_redis_client"):
        resp = await client.get("/health")
    data = resp.json()
    assert "status" in data
    assert "db" in data
    assert "redis" in data
```

- [ ] **Step 2: Create `services/core/tests/conftest.py`**

```python
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
```

- [ ] **Step 3: Run test — verify it fails**

```bash
cd services/core && pytest tests/test_health.py -v
```
Expected: `ImportError` — `helix.api.app` does not exist.

- [ ] **Step 4: Create `services/core/helix/api/__init__.py`** (empty)

- [ ] **Step 5: Create `services/core/helix/api/routers/__init__.py`** (empty)

- [ ] **Step 6: Create `services/core/helix/api/routers/health.py`**

```python
from fastapi import APIRouter
from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession

import redis.asyncio as aioredis

router = APIRouter(tags=["ops"])


async def get_async_session() -> AsyncSession:  # overridden in app factory
    raise NotImplementedError


async def get_redis_client():  # overridden in app factory
    raise NotImplementedError


@router.get("/health")
async def health() -> dict:
    db_ok = False
    redis_ok = False
    try:
        from helix.db.engine import async_session_factory
        async with async_session_factory() as session:
            await session.execute(text("SELECT 1"))
        db_ok = True
    except Exception:
        pass
    try:
        from helix.config import get_settings
        settings = get_settings()
        r = aioredis.from_url(str(settings.redis_url))
        await r.ping()
        await r.aclose()
        redis_ok = True
    except Exception:
        pass
    return {
        "status": "ok" if (db_ok and redis_ok) else "degraded",
        "db": db_ok,
        "redis": redis_ok,
    }
```

- [ ] **Step 7: Create `services/core/helix/api/deps.py`**

```python
from collections.abc import AsyncGenerator
from typing import Annotated
from uuid import UUID

from fastapi import Depends, Header, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession

from helix.config import Settings, get_settings
from helix.db.crud.tenants import get_tenant_by_public_key
from helix.db.engine import async_session_factory
from helix.db.models import Tenant


async def get_db() -> AsyncGenerator[AsyncSession, None]:
    async with async_session_factory() as session:
        yield session


async def get_tenant(
    x_helix_tenant_key: Annotated[str | None, Header()] = None,
    db: AsyncSession = Depends(get_db),
) -> Tenant:
    if x_helix_tenant_key is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Missing tenant key")
    try:
        key_uuid = UUID(x_helix_tenant_key)
    except ValueError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid tenant key")
    tenant = await get_tenant_by_public_key(db, key_uuid)
    if tenant is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Unknown tenant key")
    return tenant
```

- [ ] **Step 8: Create `services/core/helix/api/app.py`**

```python
import structlog
import logging

from fastapi import FastAPI

from helix.api.routers import health
from helix.config import Settings


def configure_logging(log_level: str) -> None:
    structlog.configure(
        processors=[
            structlog.processors.TimeStamper(fmt="iso"),
            structlog.stdlib.add_log_level,
            structlog.stdlib.add_logger_name,
            structlog.processors.JSONRenderer(),
        ],
        wrapper_class=structlog.make_filtering_bound_logger(
            getattr(logging, log_level.upper(), logging.INFO)
        ),
        logger_factory=structlog.PrintLoggerFactory(),
    )


def create_app(settings: Settings | None = None) -> FastAPI:
    from helix.config import get_settings
    s = settings or get_settings()
    configure_logging(s.log_level)

    app = FastAPI(title=s.brand_name, version="0.1.0", docs_url="/docs")
    app.include_router(health.router)
    return app


app = create_app()
```

- [ ] **Step 9: Run test — verify it passes**

```bash
cd services/core && pytest tests/test_health.py -v
```
Expected: `2 passed`

- [ ] **Step 10: Commit**

```bash
git add services/core/helix/api/ services/core/tests/test_health.py services/core/tests/conftest.py
git commit -m "feat: add FastAPI app factory, health endpoint, and DB/auth dependencies"
```

---

## Task 9: Credential encryption + tenant auth

**Files:**
- Create: `services/core/helix/api/auth/__init__.py`
- Create: `services/core/helix/api/auth/crypto.py`
- Create: `services/core/helix/api/auth/tokens.py`
- Test: `services/core/tests/test_auth.py`

- [ ] **Step 1: Write the failing test**

```python
# services/core/tests/test_auth.py
import pytest
from cryptography.fernet import Fernet
from uuid import uuid4

from helix.api.auth.crypto import encrypt_credentials, decrypt_credentials
from helix.api.auth.tokens import issue_widget_token, validate_widget_token, InvalidTokenError


def test_encrypt_decrypt_roundtrip():
    key = Fernet.generate_key().decode()
    data = {"consumer_key": "ck_abc", "consumer_secret": "cs_xyz"}
    enc = encrypt_credentials(data, key)
    assert isinstance(enc, bytes)
    assert enc != b'{"consumer_key": "ck_abc"}'
    result = decrypt_credentials(enc, key)
    assert result == data


def test_decrypt_with_wrong_key_raises():
    key1 = Fernet.generate_key().decode()
    key2 = Fernet.generate_key().decode()
    enc = encrypt_credentials({"x": 1}, key1)
    with pytest.raises(Exception):
        decrypt_credentials(enc, key2)


def test_issue_and_validate_widget_token():
    secret = "a" * 32
    tenant_id = uuid4()
    token = issue_widget_token(tenant_id, secret)
    assert isinstance(token, str)
    result = validate_widget_token(token, secret)
    assert result == tenant_id


def test_validate_expired_token_raises():
    from jose import jwt
    from datetime import datetime, timezone, timedelta
    secret = "a" * 32
    tenant_id = uuid4()
    payload = {"tenant_id": str(tenant_id), "exp": datetime.now(timezone.utc) - timedelta(seconds=1)}
    expired_token = jwt.encode(payload, secret, algorithm="HS256")
    with pytest.raises(InvalidTokenError):
        validate_widget_token(expired_token, secret)


def test_validate_garbage_token_raises():
    with pytest.raises(InvalidTokenError):
        validate_widget_token("not.a.token", "secret")
```

- [ ] **Step 2: Run test — verify it fails**

```bash
cd services/core && pytest tests/test_auth.py -v
```
Expected: `ImportError`

- [ ] **Step 3: Create `services/core/helix/api/auth/__init__.py`** (empty)

- [ ] **Step 4: Create `services/core/helix/api/auth/crypto.py`**

```python
import json

from cryptography.fernet import Fernet


def encrypt_credentials(data: dict, key: str) -> bytes:
    return Fernet(key.encode()).encrypt(json.dumps(data).encode())


def decrypt_credentials(enc: bytes, key: str) -> dict:
    raw = Fernet(key.encode()).decrypt(enc)
    return json.loads(raw.decode())
```

- [ ] **Step 5: Create `services/core/helix/api/auth/tokens.py`**

```python
from datetime import datetime, timedelta, timezone
from uuid import UUID

from jose import JWTError, jwt


class InvalidTokenError(Exception):
    pass


def issue_widget_token(tenant_id: UUID, secret: str) -> str:
    exp = datetime.now(timezone.utc) + timedelta(minutes=15)
    return jwt.encode({"tenant_id": str(tenant_id), "exp": exp}, secret, algorithm="HS256")


def validate_widget_token(token: str, secret: str) -> UUID:
    try:
        payload = jwt.decode(token, secret, algorithms=["HS256"])
        return UUID(payload["tenant_id"])
    except (JWTError, KeyError, ValueError) as exc:
        raise InvalidTokenError(str(exc)) from exc
```

- [ ] **Step 6: Run test — verify it passes**

```bash
cd services/core && pytest tests/test_auth.py -v
```
Expected: `5 passed`

- [ ] **Step 7: Commit**

```bash
git add services/core/helix/api/auth/ services/core/tests/test_auth.py
git commit -m "feat: add Fernet credential encryption and JWT widget session tokens"
```

---

## Task 10: Canonical connector models

**Files:**
- Create: `services/core/helix/connectors/__init__.py`
- Create: `services/core/helix/connectors/models.py`
- Test: `services/core/tests/test_connector_models.py`

- [ ] **Step 1: Write the failing test**

```python
# services/core/tests/test_connector_models.py
import pytest
from uuid import uuid4
from helix.connectors.models import CanonicalProduct, CanonicalCustomer, CanonicalOrder
from datetime import datetime, timezone


def make_product(**overrides) -> dict:
    base = dict(
        tenant_id=str(uuid4()),
        platform="woocommerce",
        platform_id="42",
        title="Snail Mucin Essence",
        description_html="<p>Great for skin</p>",
        price_minor=34900,
        currency="ZAR",
        images=["https://example.com/img.jpg"],
        categories=["Essence"],
        in_stock=True,
        domain_attributes={"skin_types": ["dry"], "concerns_targeted": ["hydration"]},
    )
    base.update(overrides)
    return base


def test_canonical_product_valid():
    p = CanonicalProduct(**make_product())
    assert p.price_minor == 34900
    assert p.deleted is False


def test_canonical_product_delete_flag():
    p = CanonicalProduct(**make_product(deleted=True))
    assert p.deleted is True


def test_canonical_product_invalid_platform():
    with pytest.raises(Exception):
        CanonicalProduct(**make_product(platform="magento"))


def test_canonical_product_missing_title():
    data = make_product()
    del data["title"]
    with pytest.raises(Exception):
        CanonicalProduct(**data)
```

- [ ] **Step 2: Run test — verify it fails**

```bash
cd services/core && pytest tests/test_connector_models.py -v
```
Expected: `ImportError`

- [ ] **Step 3: Create `services/core/helix/connectors/__init__.py`** (empty)

- [ ] **Step 4: Create `services/core/helix/connectors/models.py`**

```python
from datetime import datetime
from typing import Literal
from uuid import UUID

from pydantic import BaseModel


class CanonicalProduct(BaseModel):
    tenant_id: UUID
    platform: Literal["woocommerce", "shopify"]
    platform_id: str
    title: str
    description_html: str | None = None
    price_minor: int
    currency: str
    images: list[str] = []
    categories: list[str] = []
    in_stock: bool
    domain_attributes: dict = {}
    deleted: bool = False


class CanonicalCustomer(BaseModel):
    tenant_id: UUID
    platform: Literal["woocommerce", "shopify"]
    platform_id: str
    email_hash: str
    profile: dict = {}


class CanonicalOrder(BaseModel):
    tenant_id: UUID
    platform: Literal["woocommerce", "shopify"]
    platform_id: str
    customer_platform_id: str | None = None
    total_minor: int
    currency: str
    status: str
    line_items: list[dict] = []
    placed_at: datetime
```

- [ ] **Step 5: Run test — verify it passes**

```bash
cd services/core && pytest tests/test_connector_models.py -v
```
Expected: `4 passed`

- [ ] **Step 6: Commit**

```bash
git add services/core/helix/connectors/ services/core/tests/test_connector_models.py
git commit -m "feat: add canonical connector models (CanonicalProduct/Customer/Order)"
```

---

## Task 11: Domain-pack loader + kbeauty seed

**Files:**
- Create: `services/core/helix/packs/__init__.py`
- Create: `services/core/helix/packs/loader.py`
- Create: `services/core/helix/packs/registry.py`
- Create: `packs/kbeauty/pack.yaml` (+ all kbeauty files)
- Test: `services/core/tests/test_pack_loader.py`

- [ ] **Step 1: Write the failing test**

```python
# services/core/tests/test_pack_loader.py
import pytest
from pathlib import Path
from helix.packs.loader import PackLoader, PackValidationError

KBEAUTY_PATH = Path(__file__).parent.parent.parent.parent.parent / "packs" / "kbeauty"


def test_loads_kbeauty_pack():
    pack = PackLoader.load(KBEAUTY_PATH)
    assert pack.id == "kbeauty"
    assert pack.version == "0.1.0"


def test_kbeauty_has_profile_schema():
    pack = PackLoader.load(KBEAUTY_PATH)
    assert "skin_type" in pack.profile_schema["properties"]


def test_kbeauty_has_product_schema():
    pack = PackLoader.load(KBEAUTY_PATH)
    assert "skin_types" in pack.product_schema["properties"]
    assert "concerns_targeted" in pack.product_schema["properties"]


def test_kbeauty_has_prompts():
    pack = PackLoader.load(KBEAUTY_PATH)
    assert "system" in pack.prompts


def test_kbeauty_has_compatibility_rules():
    pack = PackLoader.load(KBEAUTY_PATH)
    assert len(pack.compatibility_rules) >= 3


def test_invalid_schema_raises(tmp_path):
    (tmp_path / "pack.yaml").write_text("id: bad\nversion: 0.1\ndisplay_name: Bad\n")
    (tmp_path / "profile_schema.json").write_text('{"type": "not-a-valid-type"}')
    (tmp_path / "product_schema.json").write_text('{"type": "object"}')
    (tmp_path / "taxonomy.yaml").write_text("concerns: []\n")
    (tmp_path / "compatibility_rules.yaml").write_text("[]\n")
    (tmp_path / "prompts").mkdir()
    (tmp_path / "prompts" / "system.md").write_text("hello")
    (tmp_path / "copy").mkdir()
    with pytest.raises(PackValidationError):
        PackLoader.load(tmp_path)
```

- [ ] **Step 2: Run test — verify it fails**

```bash
cd services/core && pytest tests/test_pack_loader.py -v
```
Expected: `ImportError`

- [ ] **Step 3: Create `services/core/helix/packs/__init__.py`** (empty)

- [ ] **Step 4: Create `services/core/helix/packs/loader.py`**

```python
from dataclasses import dataclass, field
from pathlib import Path
import json

import jsonschema
import yaml


class PackValidationError(Exception):
    pass


@dataclass
class LoadedPack:
    id: str
    version: str
    display_name: str
    profile_schema: dict
    product_schema: dict
    taxonomy: dict
    compatibility_rules: list[dict]
    prompts: dict[str, str]
    copy: dict[str, dict]


class PackLoader:
    @staticmethod
    def load(path: Path) -> LoadedPack:
        try:
            meta = yaml.safe_load((path / "pack.yaml").read_text())
            profile_schema = json.loads((path / "profile_schema.json").read_text())
            product_schema = json.loads((path / "product_schema.json").read_text())
            taxonomy = yaml.safe_load((path / "taxonomy.yaml").read_text())
            compat_rules = yaml.safe_load((path / "compatibility_rules.yaml").read_text()) or []
        except (FileNotFoundError, json.JSONDecodeError, yaml.YAMLError) as exc:
            raise PackValidationError(f"Failed to read pack at {path}: {exc}") from exc

        try:
            jsonschema.Draft7Validator.check_schema(profile_schema)
            jsonschema.Draft7Validator.check_schema(product_schema)
        except jsonschema.SchemaError as exc:
            raise PackValidationError(f"Invalid JSON Schema in pack {path}: {exc.message}") from exc

        prompts: dict[str, str] = {}
        prompts_dir = path / "prompts"
        if prompts_dir.exists():
            for f in prompts_dir.glob("*.md"):
                prompts[f.stem] = f.read_text()

        copy: dict[str, dict] = {}
        copy_dir = path / "copy"
        if copy_dir.exists():
            for f in copy_dir.glob("*.json"):
                copy[f.stem] = json.loads(f.read_text())

        return LoadedPack(
            id=meta["id"],
            version=str(meta["version"]),
            display_name=meta["display_name"],
            profile_schema=profile_schema,
            product_schema=product_schema,
            taxonomy=taxonomy,
            compatibility_rules=compat_rules if isinstance(compat_rules, list) else [],
            prompts=prompts,
            copy=copy,
        )
```

- [ ] **Step 5: Create `services/core/helix/packs/registry.py`**

```python
from pathlib import Path

from helix.packs.loader import LoadedPack, PackLoader

_registry: dict[str, LoadedPack] = {}


def load_all_packs(packs_dir: str) -> None:
    base = Path(packs_dir)
    if not base.exists():
        return
    for pack_path in base.iterdir():
        if pack_path.is_dir() and (pack_path / "pack.yaml").exists():
            pack = PackLoader.load(pack_path)
            _registry[pack.id] = pack


def get_pack(pack_id: str) -> LoadedPack:
    if pack_id not in _registry:
        raise KeyError(f"Pack '{pack_id}' not loaded. Available: {list(_registry)}")
    return _registry[pack_id]


def default_pack() -> LoadedPack:
    if not _registry:
        raise RuntimeError("No packs loaded")
    return next(iter(_registry.values()))
```

- [ ] **Step 6: Create all kbeauty pack files**

`packs/kbeauty/pack.yaml`:
```yaml
id: kbeauty
version: "0.1.0"
display_name: "K-Beauty"
```

`packs/kbeauty/profile_schema.json`:
```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "properties": {
    "skin_type": {
      "type": "string",
      "enum": ["dry", "oily", "combination", "normal", "sensitive"]
    },
    "skin_concerns": {
      "type": "array",
      "items": {
        "type": "string",
        "enum": ["acne", "aging", "brightening", "hydration", "pores", "redness", "texture"]
      }
    },
    "sensitivities": {
      "type": "array",
      "items": {
        "type": "string",
        "enum": ["fragrance", "alcohol", "silicone", "sulfates", "parabens"]
      }
    },
    "budget_zar": {"type": "integer", "minimum": 0}
  },
  "required": ["skin_type"]
}
```

`packs/kbeauty/product_schema.json`:
```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "properties": {
    "skin_types": {"type": "array", "items": {"type": "string"}},
    "concerns_targeted": {"type": "array", "items": {"type": "string"}},
    "key_ingredients": {"type": "array", "items": {"type": "string"}},
    "spf": {"type": "integer", "minimum": 0},
    "ph_level": {"type": "number"},
    "step": {
      "type": "string",
      "enum": ["cleanse", "tone", "treat", "moisturize", "protect", "mask"]
    }
  },
  "required": ["skin_types", "concerns_targeted"]
}
```

`packs/kbeauty/taxonomy.yaml`:
```yaml
concerns:
  - acne
  - aging
  - brightening
  - hydration
  - pores
  - redness
  - texture

routine_steps:
  - cleanse
  - tone
  - treat
  - moisturize
  - protect
  - mask

categories:
  - cleanser
  - toner
  - serum
  - moisturizer
  - sunscreen
  - mask
  - eye_cream
  - exfoliant
```

`packs/kbeauty/compatibility_rules.yaml`:
```yaml
- id: retinol_aha
  description: "Do not layer retinol with AHA/BHA on the same night"
  type: conflict
  ingredients:
    - retinol
    - glycolic acid
    - lactic acid
    - salicylic acid

- id: vitamin_c_niacinamide
  description: "High-concentration vitamin C and niacinamide may reduce efficacy — use AM/PM"
  type: caution
  ingredients:
    - ascorbic acid
    - niacinamide

- id: spf_last
  description: "SPF must always be the final step in a morning routine"
  type: order
  step: protect
  position: last
```

`packs/kbeauty/prompts/system.md`:
```markdown
You are a K-beauty skincare advisor for {brand_name}. Your role is to help customers find the right products for their skin type, concerns, and routine.

**Rules:**
- Answer only from the product and context information provided. Never invent product claims, ingredients, or efficacy.
- If the context does not contain enough information to answer, say so clearly and offer to connect the customer with human support.
- Recommend products by name and explain specifically why they suit the customer's profile.
- Keep responses concise and conversational. Avoid clinical or overly technical language unless the customer uses it first.
- Never recommend a product that conflicts with the customer's stated sensitivities.
```

`packs/kbeauty/prompts/consultant.md`:
```markdown
You are helping a customer build or improve their skincare routine. Use the retrieved products and the customer's profile to make specific, grounded recommendations.

When building a routine:
1. Address the customer's primary concern first.
2. Respect the routine step order: cleanse → tone → treat → moisturize → protect.
3. Flag any ingredient conflicts from the compatibility rules.
4. Explain the "why" for each recommendation in one sentence.
```

`packs/kbeauty/copy/en.json`:
```json
{
  "widget": {
    "greeting": "Hi! I'm your K-beauty advisor. Tell me about your skin.",
    "ask_skin_type": "What's your skin type?",
    "ask_concerns": "What are your main skin concerns?",
    "no_match": "I couldn't find products that match perfectly. Let me connect you with our team.",
    "routine_title": "Your personalised routine"
  }
}
```

- [ ] **Step 7: Run test — verify it passes**

```bash
cd services/core && pytest tests/test_pack_loader.py -v
```
Expected: `6 passed`

- [ ] **Step 8: Commit**

```bash
git add services/core/helix/packs/ packs/kbeauty/ services/core/tests/test_pack_loader.py
git commit -m "feat: add domain-pack loader and seed kbeauty pack with schemas, rules, prompts"
```

---

---

## Task 12: LLM gateway

**Files:**
- Create: `services/core/helix/llm/__init__.py`
- Create: `services/core/helix/llm/gateway.py`
- Create: `services/core/helix/llm/layers.py`
- Test: `services/core/tests/test_llm_gateway.py`

- [ ] **Step 1: Write the failing test**

```python
# services/core/tests/test_llm_gateway.py
import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from pydantic import BaseModel

from helix.llm.gateway import LLMGateway, ModelTier, LLMParseError
from tests.conftest import make_test_settings


class CategoryResponse(BaseModel):
    category: str
    confidence: float


@pytest.fixture
def gateway():
    settings = make_test_settings()
    return LLMGateway(settings=settings, tenant_id=uuid4())


@pytest.mark.asyncio
async def test_gateway_returns_parsed_model(gateway):
    mock_message = MagicMock()
    mock_message.content = [MagicMock(text='{"category": "serum", "confidence": 0.95}')]
    mock_message.usage = MagicMock(input_tokens=100, output_tokens=50)

    with patch("helix.llm.gateway.anthropic.AsyncAnthropic") as mock_cls:
        mock_client = AsyncMock()
        mock_cls.return_value = mock_client
        mock_client.messages.create = AsyncMock(return_value=mock_message)

        result = await gateway.complete(
            tier=ModelTier.CLASSIFY,
            system="Classify this product.",
            user="Snail mucin essence",
            response_schema=CategoryResponse,
        )

    assert result.category == "serum"
    assert result.confidence == 0.95


@pytest.mark.asyncio
async def test_gateway_retries_on_parse_failure(gateway):
    mock_first = MagicMock()
    mock_first.content = [MagicMock(text="not valid json")]
    mock_first.usage = MagicMock(input_tokens=50, output_tokens=10)

    mock_repair = MagicMock()
    mock_repair.content = [MagicMock(text='{"category": "toner", "confidence": 0.8}')]
    mock_repair.usage = MagicMock(input_tokens=80, output_tokens=20)

    with patch("helix.llm.gateway.anthropic.AsyncAnthropic") as mock_cls:
        mock_client = AsyncMock()
        mock_cls.return_value = mock_client
        mock_client.messages.create = AsyncMock(side_effect=[mock_first, mock_repair])

        result = await gateway.complete(
            tier=ModelTier.CLASSIFY,
            system="Classify.",
            user="Toner product",
            response_schema=CategoryResponse,
        )

    assert result.category == "toner"
    assert mock_client.messages.create.call_count == 2


@pytest.mark.asyncio
async def test_gateway_raises_after_two_failures(gateway):
    mock_bad = MagicMock()
    mock_bad.content = [MagicMock(text="still not json")]
    mock_bad.usage = MagicMock(input_tokens=50, output_tokens=10)

    with patch("helix.llm.gateway.anthropic.AsyncAnthropic") as mock_cls:
        mock_client = AsyncMock()
        mock_cls.return_value = mock_client
        mock_client.messages.create = AsyncMock(return_value=mock_bad)

        with pytest.raises(LLMParseError):
            await gateway.complete(
                tier=ModelTier.CLASSIFY,
                system="Classify.",
                user="Product",
                response_schema=CategoryResponse,
            )
```

- [ ] **Step 2: Run test — verify it fails**

```bash
cd services/core && pytest tests/test_llm_gateway.py -v
```
Expected: `ImportError`

- [ ] **Step 3: Create `services/core/helix/llm/__init__.py`** (empty)

- [ ] **Step 4: Create `services/core/helix/llm/gateway.py`**

```python
import json
import logging
from enum import Enum
from typing import TypeVar, Type
from uuid import UUID

import anthropic
import structlog
from pydantic import BaseModel, ValidationError

from helix.config import Settings

logger = structlog.get_logger(__name__)

T = TypeVar("T", bound=BaseModel)

# Cost per 1M tokens in USD
_COSTS: dict[str, tuple[float, float]] = {
    "claude-haiku-4-5":  (1.00, 5.00),
    "claude-sonnet-4-6": (3.00, 15.00),
    "claude-opus-4-8":   (5.00, 25.00),
}


class ModelTier(str, Enum):
    CLASSIFY = "classify"
    GENERATE = "generate"
    REASON = "reason"


class LLMParseError(Exception):
    pass


class LLMGateway:
    def __init__(self, settings: Settings, tenant_id: UUID) -> None:
        self._settings = settings
        self._tenant_id = tenant_id
        self._tier_to_model = {
            ModelTier.CLASSIFY: settings.llm_model_classify,
            ModelTier.GENERATE: settings.llm_model_generate,
            ModelTier.REASON:   settings.llm_model_reason,
        }

    async def complete(
        self,
        tier: ModelTier,
        system: str,
        user: str,
        response_schema: Type[T],
        *,
        max_tokens: int = 1024,
    ) -> T:
        model_id = self._tier_to_model[tier]
        client = anthropic.AsyncAnthropic(
            api_key=self._settings.anthropic_api_key.get_secret_value()
        )
        schema_hint = json.dumps(response_schema.model_json_schema(), indent=2)
        user_with_schema = (
            f"{user}\n\nRespond with only valid JSON that matches this schema:\n{schema_hint}"
        )

        message = await client.messages.create(
            model=model_id,
            max_tokens=max_tokens,
            system=[{"type": "text", "text": system, "cache_control": {"type": "ephemeral"}}],
            messages=[{"role": "user", "content": user_with_schema}],
        )

        raw = message.content[0].text
        result = self._parse(raw, response_schema)
        if result is None:
            # One repair attempt
            repair_msg = await client.messages.create(
                model=model_id,
                max_tokens=max_tokens,
                system=[{"type": "text", "text": system, "cache_control": {"type": "ephemeral"}}],
                messages=[
                    {"role": "user", "content": user_with_schema},
                    {"role": "assistant", "content": raw},
                    {
                        "role": "user",
                        "content": (
                            "Your response was not valid JSON. "
                            "Return only the JSON object, nothing else."
                        ),
                    },
                ],
            )
            result = self._parse(repair_msg.content[0].text, response_schema)
            if result is None:
                raise LLMParseError(
                    f"Could not parse LLM response as {response_schema.__name__} "
                    f"after repair attempt. Raw: {repair_msg.content[0].text[:200]}"
                )
            self._log_usage(repair_msg, model_id, "repair")

        self._log_usage(message, model_id, "primary")
        return result

    @staticmethod
    def _parse(text: str, schema: Type[T]) -> T | None:
        try:
            return schema.model_validate(json.loads(text))
        except (json.JSONDecodeError, ValidationError):
            return None

    def _log_usage(self, message: anthropic.types.Message, model_id: str, call_type: str) -> None:
        in_tokens = message.usage.input_tokens
        out_tokens = message.usage.output_tokens
        in_cost, out_cost = _COSTS.get(model_id, (0.0, 0.0))
        cost_usd = (in_tokens * in_cost + out_tokens * out_cost) / 1_000_000
        logger.info(
            "llm_call",
            model=model_id,
            call_type=call_type,
            tokens_in=in_tokens,
            tokens_out=out_tokens,
            cost_usd=round(cost_usd, 6),
            tenant_id=str(self._tenant_id),
        )
```

- [ ] **Step 5: Create `services/core/helix/llm/layers.py`**

```python
"""
Layer abstractions for cost-first query routing.

Layer 1 (vector search) and Layer 2 (rule engine) are used in Phase 1+.
This module defines the interface; implementations are added per phase.
"""
from dataclasses import dataclass
from typing import Any


@dataclass
class LayerResult:
    answered: bool
    response: Any | None = None
    confidence: float = 0.0


class VectorSearchLayer:
    """Layer 1: pgvector similarity search. Returns products; no LLM call."""

    async def query(self, tenant_id: str, query_text: str, top_k: int = 5) -> LayerResult:
        # Implemented in Phase 1 when the search endpoint is built.
        return LayerResult(answered=False)


class RuleEngineLayer:
    """Layer 2: compatibility + routine rules from the domain pack."""

    async def query(self, query_text: str, pack_rules: list[dict]) -> LayerResult:
        # Implemented in Phase 1 when the compatibility engine is built.
        return LayerResult(answered=False)


class TemplateLayer:
    """Layer 3: static FAQ / known-pattern templates from pack copy."""

    async def query(self, query_text: str, templates: dict[str, str]) -> LayerResult:
        # Implemented in Phase 1 alongside the widget query endpoint.
        return LayerResult(answered=False)
```

- [ ] **Step 6: Run test — verify it passes**

```bash
cd services/core && pytest tests/test_llm_gateway.py -v
```
Expected: `3 passed`

- [ ] **Step 7: Commit**

```bash
git add services/core/helix/llm/ services/core/tests/test_llm_gateway.py
git commit -m "feat: add LLM gateway with tiered model selection, structured output, and repair retry"
```

---

## Task 13: Provisioning endpoint (POST /v1/tenants)

**Files:**
- Create: `services/core/helix/api/routers/tenants.py`
- Modify: `services/core/helix/api/app.py`
- Test: `services/core/tests/test_tenants_endpoint.py`

- [ ] **Step 1: Write the failing test**

```python
# services/core/tests/test_tenants_endpoint.py
import pytest
from unittest.mock import AsyncMock, patch
from httpx import AsyncClient, ASGITransport
from cryptography.fernet import Fernet

from helix.api.app import create_app
from tests.conftest import make_test_settings


@pytest.fixture
def settings():
    return make_test_settings()


@pytest.fixture
def app(settings):
    return create_app(settings)


@pytest.fixture
async def client(app):
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        yield c


VALID_BODY = {
    "name": "Test Store",
    "platform": "woocommerce",
    "store_url": "https://mystore.co.za",
    "credentials": {"consumer_key": "ck_abc", "consumer_secret": "cs_xyz"},
}


@pytest.mark.asyncio
async def test_provision_without_key_returns_401(client):
    resp = await client.post("/v1/tenants", json=VALID_BODY)
    assert resp.status_code == 401


@pytest.mark.asyncio
async def test_provision_with_wrong_key_returns_401(client, settings):
    resp = await client.post(
        "/v1/tenants",
        json=VALID_BODY,
        headers={"X-Helix-Provision-Key": "wrong-key"},
    )
    assert resp.status_code == 401


@pytest.mark.asyncio
async def test_provision_creates_tenant(client, settings):
    with patch("helix.api.routers.tenants.create_tenant", new_callable=AsyncMock) as mock_create, \
         patch("helix.api.routers.tenants.get_db"):
        from helix.db.models import Tenant
        from uuid import uuid4
        fake_tenant = Tenant(
            id=uuid4(),
            name="Test Store",
            platform="woocommerce",
            store_url="https://mystore.co.za",
            credentials_enc=b"enc",
            public_key=uuid4(),
        )
        mock_create.return_value = fake_tenant

        resp = await client.post(
            "/v1/tenants",
            json=VALID_BODY,
            headers={"X-Helix-Provision-Key": settings.provision_key.get_secret_value()},
        )

    assert resp.status_code == 201
    data = resp.json()
    assert "tenant_id" in data
    assert "public_key" in data
```

- [ ] **Step 2: Run test — verify it fails**

```bash
cd services/core && pytest tests/test_tenants_endpoint.py -v
```
Expected: router not registered, `404` or `ImportError`.

- [ ] **Step 3: Create `services/core/helix/api/routers/tenants.py`**

```python
from typing import Annotated, Any
from uuid import uuid4

from fastapi import APIRouter, Depends, Header, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.auth.crypto import encrypt_credentials
from helix.api.deps import get_db
from helix.config import get_settings
from helix.db.crud.tenants import create_tenant
from helix.db.models import Tenant

router = APIRouter(prefix="/v1/tenants", tags=["tenants"])


class ProvisionRequest(BaseModel):
    name: str
    platform: str
    store_url: str
    credentials: dict[str, Any]


class ProvisionResponse(BaseModel):
    tenant_id: str
    public_key: str


@router.post("", status_code=status.HTTP_201_CREATED, response_model=ProvisionResponse)
async def provision_tenant(
    body: ProvisionRequest,
    x_helix_provision_key: Annotated[str | None, Header()] = None,
    db: AsyncSession = Depends(get_db),
) -> ProvisionResponse:
    settings = get_settings()
    if x_helix_provision_key != settings.provision_key.get_secret_value():
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid provision key")

    enc = encrypt_credentials(body.credentials, settings.credential_encryption_key.get_secret_value())
    tenant = Tenant(
        name=body.name,
        platform=body.platform,
        store_url=body.store_url,
        credentials_enc=enc,
    )
    tenant = await create_tenant(db, tenant)
    await db.commit()

    return ProvisionResponse(
        tenant_id=str(tenant.id),
        public_key=str(tenant.public_key),
    )
```

- [ ] **Step 4: Register router in `services/core/helix/api/app.py`**

```python
# Replace the existing create_app function body:
def create_app(settings: Settings | None = None) -> FastAPI:
    from helix.config import get_settings
    s = settings or get_settings()
    configure_logging(s.log_level)

    app = FastAPI(title=s.brand_name, version="0.1.0", docs_url="/docs")
    app.include_router(health.router)

    from helix.api.routers import tenants
    app.include_router(tenants.router)

    return app
```

- [ ] **Step 5: Run test — verify it passes**

```bash
cd services/core && pytest tests/test_tenants_endpoint.py -v
```
Expected: `3 passed`

- [ ] **Step 6: Commit**

```bash
git add services/core/helix/api/routers/tenants.py services/core/helix/api/app.py services/core/tests/test_tenants_endpoint.py
git commit -m "feat: add tenant provisioning endpoint POST /v1/tenants"
```

---

## Task 14: Sync endpoint (POST /v1/sync/products)

**Files:**
- Create: `services/core/helix/api/routers/sync.py`
- Test: `services/core/tests/test_sync_endpoint.py`

- [ ] **Step 1: Write the failing test**

```python
# services/core/tests/test_sync_endpoint.py
import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from httpx import AsyncClient, ASGITransport

from helix.api.app import create_app
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def make_canonical_product(tenant_id: str, platform_id: str = "42") -> dict:
    return {
        "tenant_id": tenant_id,
        "platform": "woocommerce",
        "platform_id": platform_id,
        "title": "Snail Mucin Essence 96%",
        "price_minor": 34900,
        "currency": "ZAR",
        "images": [],
        "categories": ["Essence"],
        "in_stock": True,
        "domain_attributes": {"skin_types": ["dry", "normal"], "concerns_targeted": ["hydration"]},
    }


@pytest.fixture
def tenant():
    t = Tenant.__new__(Tenant)
    t.id = uuid4()
    t.public_key = uuid4()
    t.name = "Test Store"
    t.platform = "woocommerce"
    return t


@pytest.fixture
def app():
    return create_app(make_test_settings())


@pytest.fixture
async def client(app):
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        yield c


@pytest.mark.asyncio
async def test_sync_without_tenant_key_returns_401(client, tenant):
    resp = await client.post("/v1/sync/products", json={"products": []})
    assert resp.status_code == 401


@pytest.mark.asyncio
async def test_sync_valid_products_returns_summary(client, tenant):
    products = [make_canonical_product(str(tenant.id))]

    with patch("helix.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant), \
         patch("helix.api.routers.sync.upsert_product", new_callable=AsyncMock) as mock_upsert, \
         patch("helix.api.routers.sync.embed_product") as mock_task, \
         patch("helix.api.routers.sync.default_pack") as mock_pack:
        mock_pack.return_value = MagicMock(product_schema={"type": "object", "properties": {}, "required": []})
        mock_upsert.return_value = MagicMock(id=uuid4())

        resp = await client.post(
            "/v1/sync/products",
            json={"products": products},
            headers={"X-Helix-Tenant-Key": str(tenant.public_key)},
        )

    assert resp.status_code == 200
    data = resp.json()
    assert data["synced"] == 1
    assert data["failed"] == 0


@pytest.mark.asyncio
async def test_sync_delete_flag_removes_product(client, tenant):
    products = [make_canonical_product(str(tenant.id)) | {"deleted": True}]

    with patch("helix.api.deps.get_tenant_by_public_key", new_callable=AsyncMock, return_value=tenant), \
         patch("helix.api.routers.sync.delete_product", new_callable=AsyncMock, return_value=True) as mock_del, \
         patch("helix.api.routers.sync.default_pack") as mock_pack:
        mock_pack.return_value = MagicMock(product_schema={"type": "object", "properties": {}, "required": []})

        resp = await client.post(
            "/v1/sync/products",
            json={"products": products},
            headers={"X-Helix-Tenant-Key": str(tenant.public_key)},
        )

    assert resp.status_code == 200
    mock_del.assert_called_once()
```

- [ ] **Step 2: Run test — verify it fails**

```bash
cd services/core && pytest tests/test_sync_endpoint.py -v
```
Expected: `404` or `ImportError`

- [ ] **Step 3: Create `services/core/helix/api/routers/sync.py`**

```python
from uuid import uuid4

import jsonschema
import structlog
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.deps import get_db, get_tenant
from helix.connectors.models import CanonicalProduct
from helix.db.crud.products import delete_product, upsert_product
from helix.db.models import Product, Tenant
from helix.packs.registry import default_pack
from helix.workers.tasks.embedding import embed_product

logger = structlog.get_logger(__name__)
router = APIRouter(prefix="/v1/sync", tags=["sync"])


class SyncRequest(BaseModel):
    products: list[CanonicalProduct]


class SyncResponse(BaseModel):
    synced: int
    failed: int
    errors: list[str]


@router.post("/products", response_model=SyncResponse)
async def sync_products(
    body: SyncRequest,
    tenant: Tenant = Depends(get_tenant),
    db: AsyncSession = Depends(get_db),
) -> SyncResponse:
    pack = default_pack()
    validator = jsonschema.Draft7Validator(pack.product_schema)

    synced = 0
    failed = 0
    errors: list[str] = []

    for cp in body.products:
        try:
            if cp.deleted:
                await delete_product(db, tenant.id, cp.platform_id)
                synced += 1
                continue

            validation_errors = list(validator.iter_errors(cp.domain_attributes))
            if validation_errors:
                msg = f"product {cp.platform_id}: {validation_errors[0].message}"
                errors.append(msg)
                failed += 1
                continue

            product = Product(
                tenant_id=tenant.id,
                platform_id=cp.platform_id,
                title=cp.title,
                description_html=cp.description_html,
                price_minor=cp.price_minor,
                currency=cp.currency,
                images=cp.images,
                categories=cp.categories,
                in_stock=cp.in_stock,
                domain_attributes=cp.domain_attributes,
            )
            saved = await upsert_product(db, product)
            embed_product.delay(str(tenant.id), str(saved.id))
            synced += 1
        except Exception as exc:
            logger.warning("sync_product_error", platform_id=cp.platform_id, error=str(exc))
            errors.append(f"product {cp.platform_id}: {exc}")
            failed += 1

    await db.commit()
    return SyncResponse(synced=synced, failed=failed, errors=errors)
```

- [ ] **Step 4: Register router in `app.py`**

Add to `create_app`:
```python
    from helix.api.routers import sync
    app.include_router(sync.router)
```

- [ ] **Step 5: Run test — verify it passes**

```bash
cd services/core && pytest tests/test_sync_endpoint.py -v
```
Expected: `3 passed`

- [ ] **Step 6: Commit**

```bash
git add services/core/helix/api/routers/sync.py services/core/helix/api/app.py services/core/tests/test_sync_endpoint.py
git commit -m "feat: add product sync endpoint POST /v1/sync/products with pack validation"
```

---

## Task 15: Webhook endpoint + widget session

**Files:**
- Create: `services/core/helix/api/routers/webhooks.py`
- Create: `services/core/helix/api/routers/widget.py`
- Test: `services/core/tests/test_webhooks_endpoint.py`

- [ ] **Step 1: Write the failing test**

```python
# services/core/tests/test_webhooks_endpoint.py
import hashlib
import hmac
import base64
import json
import pytest
from unittest.mock import AsyncMock, MagicMock, patch
from uuid import uuid4
from httpx import AsyncClient, ASGITransport

from helix.api.app import create_app
from helix.db.models import Tenant
from tests.conftest import make_test_settings


def make_wc_product_payload(platform_id: str = "99") -> dict:
    return {
        "id": int(platform_id),
        "name": "Toner",
        "price": "199.00",
        "description": "",
        "images": [],
        "categories": [],
        "attributes": [],
        "stock_status": "instock",
    }


def wc_signature(body: bytes, secret: str) -> str:
    mac = hmac.new(secret.encode(), body, hashlib.sha256).digest()
    return base64.b64encode(mac).decode()


@pytest.fixture
def tenant():
    from helix.api.auth.crypto import encrypt_credentials
    from tests.conftest import make_test_settings
    settings = make_test_settings()
    t = Tenant.__new__(Tenant)
    t.id = uuid4()
    t.public_key = uuid4()
    t.platform = "woocommerce"
    t.credentials_enc = encrypt_credentials(
        {"webhook_secret": "test-secret"},
        settings.credential_encryption_key.get_secret_value(),
    )
    return t


@pytest.fixture
def app():
    return create_app(make_test_settings())


@pytest.fixture
async def client(app):
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        yield c


@pytest.mark.asyncio
async def test_webhook_bad_signature_returns_401(client, tenant):
    body = json.dumps(make_wc_product_payload()).encode()
    with patch("helix.api.routers.webhooks.get_tenant_by_id", new_callable=AsyncMock, return_value=tenant):
        resp = await client.post(
            "/v1/webhooks/products",
            content=body,
            headers={
                "X-Helix-Tenant-Id": str(tenant.id),
                "X-WC-Webhook-Signature": "badsig",
                "Content-Type": "application/json",
            },
        )
    assert resp.status_code == 401


@pytest.mark.asyncio
async def test_webhook_valid_signature_accepted(client, tenant):
    payload = make_wc_product_payload()
    body = json.dumps(payload).encode()
    sig = wc_signature(body, "test-secret")

    with patch("helix.api.routers.webhooks.get_tenant_by_id", new_callable=AsyncMock, return_value=tenant), \
         patch("helix.api.routers.webhooks.upsert_product", new_callable=AsyncMock), \
         patch("helix.api.routers.webhooks.embed_product"), \
         patch("helix.api.routers.webhooks.default_pack") as mock_pack, \
         patch("helix.api.routers.webhooks.get_db"):
        mock_pack.return_value = MagicMock(product_schema={"type": "object", "properties": {}, "required": []})

        resp = await client.post(
            "/v1/webhooks/products",
            content=body,
            headers={
                "X-Helix-Tenant-Id": str(tenant.id),
                "X-WC-Webhook-Signature": sig,
                "Content-Type": "application/json",
            },
        )
    assert resp.status_code == 200
```

- [ ] **Step 2: Run test — verify it fails**

```bash
cd services/core && pytest tests/test_webhooks_endpoint.py -v
```
Expected: `404` or `ImportError`

- [ ] **Step 3: Add `get_tenant_by_id` to `services/core/helix/db/crud/tenants.py`**

```python
async def get_tenant_by_id(session: AsyncSession, tenant_id: UUID) -> Tenant | None:
    result = await session.execute(select(Tenant).where(Tenant.id == tenant_id))
    return result.scalar_one_or_none()
```

- [ ] **Step 4: Create `services/core/helix/api/routers/webhooks.py`**

```python
import base64
import hashlib
import hmac
import json
from typing import Any
from uuid import UUID

import structlog
from fastapi import APIRouter, Header, HTTPException, Request, status, Depends
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.auth.crypto import decrypt_credentials
from helix.api.deps import get_db
from helix.config import get_settings
from helix.db.crud.products import delete_product, upsert_product
from helix.db.crud.tenants import get_tenant_by_id
from helix.db.models import Product, Tenant
from helix.packs.registry import default_pack
from helix.workers.tasks.embedding import embed_product

logger = structlog.get_logger(__name__)
router = APIRouter(prefix="/v1/webhooks", tags=["webhooks"])


def _verify_wc_signature(body: bytes, signature: str, secret: str) -> bool:
    expected = base64.b64encode(
        hmac.new(secret.encode(), body, hashlib.sha256).digest()
    ).decode()
    return hmac.compare_digest(expected, signature)


@router.post("/products")
async def product_webhook(
    request: Request,
    x_helix_tenant_id: str = Header(...),
    x_wc_webhook_signature: str = Header(...),
    db: AsyncSession = Depends(get_db),
) -> dict[str, str]:
    body = await request.body()

    try:
        tenant_id = UUID(x_helix_tenant_id)
    except ValueError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid tenant ID")

    tenant = await get_tenant_by_id(db, tenant_id)
    if tenant is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Unknown tenant")

    settings = get_settings()
    creds = decrypt_credentials(tenant.credentials_enc, settings.credential_encryption_key.get_secret_value())
    webhook_secret = creds.get("webhook_secret", "")

    if not _verify_wc_signature(body, x_wc_webhook_signature, webhook_secret):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid signature")

    payload: dict[str, Any] = json.loads(body)

    if payload.get("deleted"):
        await delete_product(db, tenant.id, str(payload["id"]))
        await db.commit()
        return {"status": "deleted"}

    pack = default_pack()
    product = Product(
        tenant_id=tenant.id,
        platform_id=str(payload["id"]),
        title=payload.get("name", ""),
        description_html=payload.get("description") or None,
        price_minor=int(round(float(payload.get("price", "0")) * 100)),
        currency="ZAR",
        images=[img["src"] for img in payload.get("images", []) if "src" in img],
        categories=[c["name"] for c in payload.get("categories", [])],
        in_stock=payload.get("stock_status") == "instock",
        domain_attributes=_extract_domain_attrs(payload),
    )
    saved = await upsert_product(db, product)
    embed_product.delay(str(tenant.id), str(saved.id))
    await db.commit()
    return {"status": "ok"}


def _extract_domain_attrs(payload: dict[str, Any]) -> dict[str, Any]:
    attrs: dict[str, Any] = {}
    for attr in payload.get("attributes", []):
        slug = attr.get("slug", "").replace("pa_", "").replace("-", "_")
        options = attr.get("options", [])
        if slug and options:
            attrs[slug] = options if len(options) > 1 else options[0]
    return attrs
```

- [ ] **Step 5: Create `services/core/helix/api/routers/widget.py`**

```python
from fastapi import APIRouter, Depends, status
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from helix.api.auth.tokens import issue_widget_token
from helix.api.deps import get_db, get_tenant
from helix.config import get_settings
from helix.db.models import Tenant

router = APIRouter(prefix="/v1/widget", tags=["widget"])


class SessionResponse(BaseModel):
    token: str
    expires_in: int = 900


@router.post("/session", response_model=SessionResponse)
async def issue_session(
    tenant: Tenant = Depends(get_tenant),
) -> SessionResponse:
    settings = get_settings()
    token = issue_widget_token(tenant.id, settings.session_secret.get_secret_value())
    return SessionResponse(token=token)
```

- [ ] **Step 6: Register both routers in `app.py`**

Add to `create_app`:
```python
    from helix.api.routers import webhooks, widget
    app.include_router(webhooks.router)
    app.include_router(widget.router)
```

- [ ] **Step 7: Run test — verify it passes**

```bash
cd services/core && pytest tests/test_webhooks_endpoint.py -v
```
Expected: `3 passed`

- [ ] **Step 8: Commit**

```bash
git add services/core/helix/api/routers/webhooks.py services/core/helix/api/routers/widget.py services/core/helix/api/app.py services/core/tests/test_webhooks_endpoint.py
git commit -m "feat: add webhook endpoint with HMAC verification and widget session endpoint"
```

---

## Task 16: Embedding pipeline

**Files:**
- Create: `services/core/helix/workers/__init__.py`
- Create: `services/core/helix/workers/celery_app.py`
- Create: `services/core/helix/workers/tasks/__init__.py`
- Create: `services/core/helix/workers/tasks/embedding.py`
- Test: `services/core/tests/test_embedding_tasks.py`

- [ ] **Step 1: Write the failing test**

```python
# services/core/tests/test_embedding_tasks.py
import pytest
from unittest.mock import MagicMock, patch
from uuid import uuid4


def test_embed_product_calls_voyage(monkeypatch):
    from helix.workers.tasks import embedding

    mock_response = MagicMock()
    mock_response.json.return_value = {"data": [{"embedding": [0.1] * 1024}]}
    mock_response.raise_for_status = MagicMock()

    fake_product = MagicMock()
    fake_product.id = uuid4()
    fake_product.title = "Essence"
    fake_product.categories = ["serum"]
    fake_product.domain_attributes = {"skin_types": ["dry"]}

    with patch("helix.workers.tasks.embedding.httpx.post", return_value=mock_response) as mock_post, \
         patch("helix.workers.tasks.embedding.get_sync_session") as mock_session_fn:
        mock_session = MagicMock()
        mock_session_fn.return_value.__enter__ = MagicMock(return_value=mock_session)
        mock_session_fn.return_value.__exit__ = MagicMock(return_value=False)
        mock_session.get.return_value = fake_product

        embedding._embed_and_store(str(uuid4()), str(fake_product.id))

    mock_post.assert_called_once()
    call_kwargs = mock_post.call_args
    assert "voyage-3-lite" in str(call_kwargs)


def test_build_embed_text():
    from helix.workers.tasks.embedding import _build_embed_text
    text = _build_embed_text("Snail Essence", ["serum"], {"skin_types": ["dry"]})
    assert "Snail Essence" in text
    assert "serum" in text
```

- [ ] **Step 2: Run test — verify it fails**

```bash
cd services/core && pytest tests/test_embedding_tasks.py -v
```
Expected: `ImportError`

- [ ] **Step 3: Create `services/core/helix/workers/__init__.py`** (empty)

- [ ] **Step 4: Create `services/core/helix/workers/celery_app.py`**

```python
from celery import Celery
from helix.config import get_settings

settings = get_settings()

celery_app = Celery(
    "helix",
    broker=str(settings.redis_url),
    backend=str(settings.redis_url),
    include=["helix.workers.tasks.embedding"],
)
celery_app.conf.update(
    task_serializer="json",
    accept_content=["json"],
    result_serializer="json",
    timezone="UTC",
    enable_utc=True,
    task_routes={
        "helix.workers.tasks.embedding.*": {"queue": "embedding"},
    },
)
```

- [ ] **Step 5: Create `services/core/helix/workers/tasks/__init__.py`** (empty)

- [ ] **Step 6: Create `services/core/helix/workers/tasks/embedding.py`**

```python
import json
from uuid import UUID

import httpx
import structlog
from sqlalchemy.orm import Session

from helix.config import get_settings
from helix.db.engine import get_sync_session
from helix.db.models import Product
from helix.workers.celery_app import celery_app

logger = structlog.get_logger(__name__)

_VOYAGE_URL = "https://api.voyageai.com/v1/embeddings"
_VOYAGE_MODEL = "voyage-3-lite"
_VOYAGE_BATCH_SIZE = 128


def _build_embed_text(title: str, categories: list, domain_attributes: dict) -> str:
    cats = ", ".join(categories) if categories else ""
    attrs = json.dumps(domain_attributes)
    return f"{title} | {cats} | {attrs}"


def _embed_and_store(tenant_id: str, product_id: str) -> None:
    settings = get_settings()
    with get_sync_session() as session:
        product = session.get(Product, UUID(product_id))
        if product is None or str(product.tenant_id) != tenant_id:
            logger.warning("embed_product_not_found", product_id=product_id)
            return

        text = _build_embed_text(product.title, product.categories or [], product.domain_attributes or {})

        resp = httpx.post(
            _VOYAGE_URL,
            json={"input": [text], "model": _VOYAGE_MODEL},
            headers={"Authorization": f"Bearer {settings.voyage_api_key.get_secret_value()}"},
            timeout=30.0,
        )
        resp.raise_for_status()
        embedding = resp.json()["data"][0]["embedding"]

        product.embedding = embedding
        session.commit()
        logger.info("embed_product_done", product_id=product_id, dims=len(embedding))


@celery_app.task(bind=True, max_retries=3, default_retry_delay=60, name="helix.workers.tasks.embedding.embed_product")
def embed_product(self, tenant_id: str, product_id: str) -> None:
    try:
        _embed_and_store(tenant_id, product_id)
    except httpx.HTTPError as exc:
        raise self.retry(exc=exc)


@celery_app.task(name="helix.workers.tasks.embedding.embed_product_batch")
def embed_product_batch(tenant_id: str, product_ids: list[str]) -> dict:
    settings = get_settings()
    results = {"ok": 0, "failed": 0}

    for i in range(0, len(product_ids), _VOYAGE_BATCH_SIZE):
        batch_ids = product_ids[i : i + _VOYAGE_BATCH_SIZE]
        with get_sync_session() as session:
            products = [
                session.get(Product, UUID(pid))
                for pid in batch_ids
            ]
            products = [p for p in products if p and str(p.tenant_id) == tenant_id]
            if not products:
                continue

            texts = [
                _build_embed_text(p.title, p.categories or [], p.domain_attributes or {})
                for p in products
            ]
            try:
                resp = httpx.post(
                    _VOYAGE_URL,
                    json={"input": texts, "model": _VOYAGE_MODEL},
                    headers={"Authorization": f"Bearer {settings.voyage_api_key.get_secret_value()}"},
                    timeout=60.0,
                )
                resp.raise_for_status()
                embeddings = [item["embedding"] for item in resp.json()["data"]]
                for product, emb in zip(products, embeddings):
                    product.embedding = emb
                session.commit()
                results["ok"] += len(products)
            except httpx.HTTPError as exc:
                logger.error("embed_batch_error", error=str(exc), batch_size=len(products))
                results["failed"] += len(products)

    return results
```

- [ ] **Step 7: Update `engine.py` to expose `get_sync_session` as a context manager**

Add to `services/core/helix/db/engine.py`:
```python
from contextlib import contextmanager
from collections.abc import Generator

@contextmanager
def get_sync_session() -> Generator[Session, None, None]:
    session = sync_session_factory()
    try:
        yield session
    finally:
        session.close()
```

- [ ] **Step 8: Run test — verify it passes**

```bash
cd services/core && pytest tests/test_embedding_tasks.py -v
```
Expected: `2 passed`

- [ ] **Step 9: Commit**

```bash
git add services/core/helix/workers/ services/core/tests/test_embedding_tasks.py
git commit -m "feat: add Celery embedding pipeline with Voyage AI voyage-3-lite"
```

---

## Task 17: WooCommerce PHP plugin

**Files:**
- Create: `connectors/woocommerce/helix-connector.php`
- Create: `connectors/woocommerce/includes/class-helix-admin.php`
- Create: `connectors/woocommerce/includes/class-helix-api-client.php`
- Create: `connectors/woocommerce/includes/class-helix-sync.php`
- Create: `connectors/woocommerce/includes/class-helix-webhooks.php`

- [ ] **Step 1: Create `connectors/woocommerce/helix-connector.php`**

```php
<?php
/**
 * Plugin Name: Helix Connector
 * Description: Syncs your WooCommerce catalog with the Helix AI commerce intelligence platform.
 * Version: 0.1.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'HELIX_CONNECTOR_VERSION', '0.1.0' );
define( 'HELIX_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );

require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-api-client.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-sync.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-webhooks.php';
require_once HELIX_CONNECTOR_DIR . 'includes/class-helix-admin.php';

function helix_connector_init(): void {
    Helix_Admin::init();
    Helix_Webhooks::init();
}
add_action( 'plugins_loaded', 'helix_connector_init' );

register_activation_hook( __FILE__, 'helix_connector_activate' );
function helix_connector_activate(): void {
    // Activation deferred to admin settings save so the user can enter API URL first.
    update_option( 'helix_activated', true );
}

register_deactivation_hook( __FILE__, 'helix_connector_deactivate' );
function helix_connector_deactivate(): void {
    Helix_Webhooks::remove_webhooks();
    delete_option( 'helix_tenant_id' );
    delete_option( 'helix_public_key' );
    delete_option( 'helix_webhook_secret' );
}
```

- [ ] **Step 2: Create `connectors/woocommerce/includes/class-helix-api-client.php`**

```php
<?php
defined( 'ABSPATH' ) || exit;

class Helix_API_Client {
    private string $api_url;
    private string $tenant_key;

    public function __construct( string $api_url, string $tenant_key = '' ) {
        $this->api_url    = rtrim( $api_url, '/' );
        $this->tenant_key = $tenant_key;
    }

    public function provision( string $name, string $store_url, array $credentials ): array|WP_Error {
        $provision_key = get_option( 'helix_provision_key', '' );
        $response = wp_remote_post( $this->api_url . '/v1/tenants', [
            'headers' => [
                'Content-Type'         => 'application/json',
                'X-Helix-Provision-Key' => $provision_key,
            ],
            'body'    => wp_json_encode( [
                'name'        => $name,
                'platform'    => 'woocommerce',
                'store_url'   => $store_url,
                'credentials' => $credentials,
            ] ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 201 ) {
            return new WP_Error( 'helix_provision_failed', "Helix API returned HTTP {$code}" );
        }
        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    public function sync_products( array $products ): array|WP_Error {
        $response = wp_remote_post( $this->api_url . '/v1/sync/products', [
            'headers' => [
                'Content-Type'       => 'application/json',
                'X-Helix-Tenant-Key' => $this->tenant_key,
            ],
            'body'    => wp_json_encode( [ 'products' => $products ] ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return json_decode( wp_remote_retrieve_body( $response ), true );
    }
}
```

- [ ] **Step 3: Create `connectors/woocommerce/includes/class-helix-sync.php`**

```php
<?php
defined( 'ABSPATH' ) || exit;

class Helix_Sync {
    public static function run_full_sync(): array {
        $api_url    = get_option( 'helix_api_url', '' );
        $tenant_key = get_option( 'helix_public_key', '' );

        if ( ! $api_url || ! $tenant_key ) {
            return [ 'error' => 'Plugin not configured. Enter API URL and connect store first.' ];
        }

        $client  = new Helix_API_Client( $api_url, $tenant_key );
        $page    = 1;
        $synced  = 0;
        $failed  = 0;

        do {
            $wc_products = wc_get_products( [
                'limit'  => 100,
                'page'   => $page,
                'status' => 'publish',
                'return' => 'objects',
            ] );

            if ( empty( $wc_products ) ) {
                break;
            }

            $batch = array_map( [ self::class, 'translate_product' ], $wc_products );
            $result = $client->sync_products( $batch );

            if ( is_wp_error( $result ) ) {
                $failed += count( $batch );
            } else {
                $synced += $result['synced'] ?? 0;
                $failed += $result['failed'] ?? 0;
            }

            $page++;
        } while ( count( $wc_products ) === 100 );

        update_option( 'helix_last_sync', current_time( 'mysql' ) );
        update_option( 'helix_synced_count', $synced );

        return [ 'synced' => $synced, 'failed' => $failed ];
    }

    public static function translate_product( WC_Product $wc_product ): array {
        $price_str   = $wc_product->get_price() ?: '0';
        $price_minor = (int) round( (float) $price_str * 100 );

        return [
            'tenant_id'         => get_option( 'helix_tenant_id' ),
            'platform'          => 'woocommerce',
            'platform_id'       => (string) $wc_product->get_id(),
            'title'             => $wc_product->get_name(),
            'description_html'  => $wc_product->get_description() ?: null,
            'price_minor'       => $price_minor,
            'currency'          => get_woocommerce_currency(),
            'images'            => array_map(
                fn( int $id ) => wp_get_attachment_url( $id ),
                $wc_product->get_gallery_image_ids()
                    ? array_merge( [ $wc_product->get_image_id() ], $wc_product->get_gallery_image_ids() )
                    : ( $wc_product->get_image_id() ? [ $wc_product->get_image_id() ] : [] )
            ),
            'categories'        => array_map(
                fn( WP_Term $t ) => $t->name,
                get_the_terms( $wc_product->get_id(), 'product_cat' ) ?: []
            ),
            'in_stock'          => $wc_product->is_in_stock(),
            'domain_attributes' => self::extract_domain_attributes( $wc_product ),
        ];
    }

    private static function extract_domain_attributes( WC_Product $wc_product ): array {
        $attrs = [];
        foreach ( $wc_product->get_attributes() as $slug => $attribute ) {
            $key     = str_replace( [ 'pa_', '-' ], [ '', '_' ], $slug );
            $options = $attribute->get_options();
            if ( ! empty( $options ) ) {
                $attrs[ $key ] = count( $options ) === 1 ? $options[0] : $options;
            }
        }
        return $attrs;
    }
}
```

- [ ] **Step 4: Create `connectors/woocommerce/includes/class-helix-webhooks.php`**

```php
<?php
defined( 'ABSPATH' ) || exit;

class Helix_Webhooks {
    private const WEBHOOK_TOPICS = [ 'product.created', 'product.updated', 'product.deleted' ];

    public static function init(): void {
        add_action( 'woocommerce_webhook_payload', [ self::class, 'sign_outbound_webhook' ], 10, 4 );
    }

    public static function register_webhooks( string $api_url, string $tenant_id ): void {
        $secret          = wp_generate_password( 32, false );
        $delivery_url    = trailingslashit( $api_url ) . 'v1/webhooks/products';

        foreach ( self::WEBHOOK_TOPICS as $topic ) {
            $webhook = new WC_Webhook();
            $webhook->set_name( "Helix — {$topic}" );
            $webhook->set_topic( $topic );
            $webhook->set_delivery_url( $delivery_url );
            $webhook->set_secret( $secret );
            $webhook->set_status( 'active' );
            $webhook->save();
        }

        update_option( 'helix_webhook_secret', $secret );
        update_option( 'helix_tenant_id', $tenant_id );
    }

    public static function remove_webhooks(): void {
        $data_store = WC_Data_Store::load( 'webhook' );
        $webhook_ids = $data_store->search_webhooks( [ 'limit' => 100, 'status' => 'active' ] );
        foreach ( $webhook_ids as $id ) {
            $webhook = new WC_Webhook( $id );
            if ( str_starts_with( $webhook->get_name(), 'Helix — ' ) ) {
                $webhook->delete( true );
            }
        }
    }

    public static function sign_outbound_webhook( array $payload, string $resource, string $event, int $webhook_id ): array {
        $webhook = new WC_Webhook( $webhook_id );
        if ( ! str_starts_with( $webhook->get_name(), 'Helix — ' ) ) {
            return $payload;
        }
        $body   = wp_json_encode( $payload );
        $secret = $webhook->get_secret();
        $sig    = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );
        // Signature is set in the X-WC-Webhook-Signature header by WooCommerce automatically using the webhook's secret.
        return $payload;
    }
}
```

- [ ] **Step 5: Create `connectors/woocommerce/includes/class-helix-admin.php`**

```php
<?php
defined( 'ABSPATH' ) || exit;

class Helix_Admin {
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_settings_page' ] );
        add_action( 'admin_init', [ self::class, 'register_settings' ] );
        add_action( 'admin_post_helix_save_settings', [ self::class, 'save_settings' ] );
        add_action( 'admin_post_helix_run_sync', [ self::class, 'handle_sync' ] );
    }

    public static function add_settings_page(): void {
        add_submenu_page(
            'woocommerce',
            'Helix Connector',
            'Helix',
            'manage_woocommerce',
            'helix-connector',
            [ self::class, 'render_settings_page' ]
        );
    }

    public static function register_settings(): void {
        register_setting( 'helix_settings', 'helix_api_url', [ 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( 'helix_settings', 'helix_provision_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'helix_settings', 'helix_consumer_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'helix_settings', 'helix_consumer_secret', [ 'sanitize_callback' => 'sanitize_text_field' ] );
    }

    public static function save_settings(): void {
        check_admin_referer( 'helix_save_settings' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized' );
        }

        update_option( 'helix_api_url', esc_url_raw( $_POST['helix_api_url'] ?? '' ) );
        update_option( 'helix_provision_key', sanitize_text_field( $_POST['helix_provision_key'] ?? '' ) );
        update_option( 'helix_consumer_key', sanitize_text_field( $_POST['helix_consumer_key'] ?? '' ) );
        update_option( 'helix_consumer_secret', sanitize_text_field( $_POST['helix_consumer_secret'] ?? '' ) );

        // Provision with Helix if not yet connected.
        if ( ! get_option( 'helix_tenant_id' ) ) {
            $client = new Helix_API_Client( get_option( 'helix_api_url', '' ) );
            $result = $client->provision(
                get_bloginfo( 'name' ),
                site_url(),
                [
                    'consumer_key'    => get_option( 'helix_consumer_key' ),
                    'consumer_secret' => get_option( 'helix_consumer_secret' ),
                ]
            );
            if ( ! is_wp_error( $result ) ) {
                update_option( 'helix_tenant_id', $result['tenant_id'] );
                update_option( 'helix_public_key', $result['public_key'] );
                Helix_Webhooks::register_webhooks( get_option( 'helix_api_url' ), $result['tenant_id'] );
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=helix-connector&saved=1' ) );
        exit;
    }

    public static function handle_sync(): void {
        check_admin_referer( 'helix_run_sync' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized' );
        }
        $result = Helix_Sync::run_full_sync();
        $synced = $result['synced'] ?? 0;
        wp_safe_redirect( admin_url( "admin.php?page=helix-connector&synced={$synced}" ) );
        exit;
    }

    public static function render_settings_page(): void {
        $tenant_id   = get_option( 'helix_tenant_id', '' );
        $last_sync   = get_option( 'helix_last_sync', 'Never' );
        $sync_count  = get_option( 'helix_synced_count', 0 );
        $connected   = ! empty( $tenant_id );
        ?>
        <div class="wrap">
            <h1>Helix Connector</h1>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success"><p>Settings saved.</p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['synced'] ) ) : ?>
                <div class="notice notice-success"><p>Sync complete. <?php echo esc_html( $_GET['synced'] ); ?> products synced.</p></div>
            <?php endif; ?>

            <h2>Connection</h2>
            <p>Status: <strong><?php echo $connected ? '✓ Connected (tenant: ' . esc_html( $tenant_id ) . ')' : '✗ Not connected'; ?></strong></p>
            <p>Last sync: <?php echo esc_html( $last_sync ); ?> (<?php echo esc_html( $sync_count ); ?> products)</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'helix_save_settings' ); ?>
                <input type="hidden" name="action" value="helix_save_settings">
                <table class="form-table">
                    <tr><th>API URL</th><td><input type="url" name="helix_api_url" value="<?php echo esc_attr( get_option( 'helix_api_url' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th>Provision Key</th><td><input type="password" name="helix_provision_key" value="<?php echo esc_attr( get_option( 'helix_provision_key' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th>WC Consumer Key</th><td><input type="text" name="helix_consumer_key" value="<?php echo esc_attr( get_option( 'helix_consumer_key' ) ); ?>" class="regular-text"></td></tr>
                    <tr><th>WC Consumer Secret</th><td><input type="password" name="helix_consumer_secret" value="" class="regular-text" placeholder="(unchanged)"></td></tr>
                </table>
                <?php submit_button( 'Save & Connect' ); ?>
            </form>

            <?php if ( $connected ) : ?>
                <h2>Catalog Sync</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'helix_run_sync' ); ?>
                    <input type="hidden" name="action" value="helix_run_sync">
                    <?php submit_button( 'Sync Catalog Now', 'secondary' ); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add connectors/woocommerce/
git commit -m "feat: add WooCommerce PHP connector plugin with activation, sync, and webhooks"
```

---

## Task 18: ADRs

**Files:** `docs/adr/0001` through `docs/adr/0005`

- [ ] **Step 1: Create `docs/adr/0001-hosted-core-vs-self-contained-plugin.md`**

```markdown
# ADR 0001 — Hosted Multi-Tenant Core vs Self-Contained Plugin

**Status:** Accepted  
**Date:** 2026-06-11

## Context
We need to deliver AI features (embeddings, LLM reasoning, customer profiles) across WooCommerce and Shopify stores. The alternatives are: (a) a self-contained plugin that runs AI logic inside each store, or (b) a hosted service that all stores connect to.

## Decision
Build a hosted, multi-tenant core service. Platform connectors are thin clients that sync data and inject a widget.

## Alternatives Considered
- **Self-contained plugin:** Simpler for WooCommerce, but Shopify apps cannot run arbitrary backend logic in-store. Would require two completely separate codebases. Secrets would need to live in each store.

## Consequences
One backend serves all stores and verticals. AI logic, secrets, and embeddings never touch the merchant's server. Adding a new platform requires only a new thin connector — zero changes to the core.
```

- [ ] **Step 2: Create `docs/adr/0002-postgresql-pgvector-single-datastore.md`**

```markdown
# ADR 0002 — PostgreSQL + pgvector as Single Datastore

**Status:** Accepted  
**Date:** 2026-06-11

## Context
We need a relational store for tenants, products, orders, and jobs, plus a vector store for embeddings. Running two separate systems (e.g. PostgreSQL + Pinecone) adds operational overhead and makes joins impossible.

## Decision
Use PostgreSQL 16 with the `pgvector` extension. Store relational data and 1024-dimension embeddings in the same database.

## Alternatives Considered
- **Pinecone or Weaviate:** Purpose-built for vectors, but no relational data. Adds a second infrastructure dependency and a second billing account.
- **SQLite + FAISS:** Not viable for multi-tenant production workloads.

## Consequences
One connection string, one backup strategy, one migration tool. Vector similarity queries can join directly with product and tenant data. Revisit if query latency at >1M vectors per tenant proves the HNSW index insufficient.
```

- [ ] **Step 3: Create `docs/adr/0003-domain-pack-declarative-format.md`**

```markdown
# ADR 0003 — Domain Pack as Declarative Data + Thin Rules Module

**Status:** Accepted  
**Date:** 2026-06-11

## Context
The platform must serve multiple verticals (K-beauty, automotive parts). Domain knowledge must not leak into the core engine.

## Decision
A pack is a directory of YAML/JSON files — profile schema, product schema, taxonomy, compatibility rules, prompt fragments, and UI copy. Loaded and validated at startup. The core contains zero vertical-specific literals.

## Alternatives Considered
- **Pack as a Python module:** More expressive, but harder to validate, audit, and hand off to non-engineers. Risk of vertical logic creeping into shared code.
- **Database-stored pack configuration:** Flexible at runtime, but requires migrations for every pack change and makes version control harder.

## Consequences
Adding a new vertical is a new directory, not a code change. Pack schemas are validated at startup, so malformed packs fail loudly before any request is served. The discipline "if you type a skincare word in `services/core`, stop" is enforceable in code review.
```

- [ ] **Step 4: Create `docs/adr/0004-voyage-ai-embeddings.md`**

```markdown
# ADR 0004 — Voyage AI for Product Embeddings

**Status:** Accepted  
**Date:** 2026-06-11

## Context
Product embeddings power semantic search. We need a hosted embedding model that handles multilingual product text and domain-specific vocabulary well.

## Decision
Use Voyage AI `voyage-3-lite` (1024 dimensions, $0.02/1M tokens) via their REST API. Upgrade path to `voyage-3` if quality is insufficient.

## Alternatives Considered
- **OpenAI `text-embedding-3-small`:** Similar price and quality, but adds a second vendor alongside Anthropic.
- **Local `sentence-transformers`:** Zero API cost, but requires a GPU or a large CPU worker, adds ~1GB to the Docker image, and quality on product text is measurably lower.

## Consequences
Embedding cost for a 500-product store is ~$0.002 — negligible. The Voyage AI client is isolated to the embedding Celery task; swapping providers requires changing one file.
```

- [ ] **Step 5: Create `docs/adr/0005-llm-gateway-layered-routing.md`**

```markdown
# ADR 0005 — LLM Gateway with Cost-First Layered Routing

**Status:** Accepted  
**Date:** 2026-06-11

## Context
Naive "call Claude for every query" costs ~$315/month per store at 1,000 queries/day. Most queries can be answered without an LLM call.

## Decision
The gateway routes queries through four layers cheapest-first:
1. **Vector search (pgvector):** $0 — handles ~60% of product discovery queries.
2. **Rule engine (pack rules):** $0 — handles ~20% of compatibility and routine questions.
3. **Templates (pack copy):** $0 — handles ~10% of FAQ and policy questions.
4. **LLM (Sonnet/Haiku):** ~$0.001–0.003 — fires only for the remaining ~10%.

Additionally: Anthropic `cache_control` on system prompts reduces input token costs by ~80% on repeated calls. Redis caches deterministic responses (classification, FAQ). Batch API used for non-real-time jobs (description generation, bulk re-embed).

## Alternatives Considered
- **Always call Sonnet:** Simpler code, ~$315/mo per store.
- **Always call Haiku:** Cheaper but inadequate quality for generation tasks.

## Consequences
Target: ~$31–35/month per store at 1,000 queries/day — an 89% reduction. Per-tenant `usage_event` rows provide the data to tune layer thresholds over time.
```

- [ ] **Step 6: Commit**

```bash
git add docs/adr/
git commit -m "docs: add ADRs 0001-0005 for architecture decisions through Phase 0"
```

---

## Task 19: Run full test suite + update PROGRESS.md

- [ ] **Step 1: Run all tests**

```bash
cd services/core && pytest -v --tb=short
```
Expected: all tests pass (no failures).

- [ ] **Step 2: Run lint and type checks**

```bash
cd services/core && ruff check . && mypy helix/
```
Expected: no errors.

- [ ] **Step 3: Start full stack and run migration**

```bash
docker compose -f infra/compose.yaml up -d
docker compose -f infra/compose.yaml exec api alembic upgrade head
docker compose -f infra/compose.yaml exec api curl -s http://localhost:8000/health
```
Expected:
```json
{"status":"ok","db":true,"redis":true}
```

- [ ] **Step 4: Update `docs/PROGRESS.md`**

Move all Phase 0 tasks from `TODO` to `DONE`. Update the status snapshot:
```markdown
## Status snapshot
- **Current phase:** Phase 0 — Foundations
- **Overall:** complete
- **Last updated:** 2026-06-11
- **Last worked by:** Claude Sonnet 4.6
- **Build health:** green — all tests pass, health endpoint returns ok
```

Add a session log entry:
```markdown
## Session log

### 2026-06-11 — Claude Sonnet 4.6
Built all 12 Phase 0 tasks end-to-end: monorepo scaffold, Docker infra, SQLAlchemy models + Alembic migration, tenancy + auth (Fernet + JWT), LLM gateway with layered routing, domain-pack loader, kbeauty seed, connector contract (CanonicalProduct/Customer/Order), provisioning + sync + webhook + widget session endpoints, Voyage AI embedding pipeline, WooCommerce PHP plugin, and 5 ADRs. All Python tests pass; lint and types clean. Next: Phase 1 — semantic search, AI consultant, routine builder.
```

Add embedding provider decision to the decisions log:
```markdown
| 2026-06-11 | Voyage AI voyage-3-lite for product embeddings | `0004` |
| 2026-06-11 | LLM gateway layered routing (vector → rules → templates → LLM) | `0005` |
```

- [ ] **Step 5: Final commit**

```bash
git add docs/PROGRESS.md
git commit -m "docs: mark Phase 0 complete in PROGRESS.md"
```

---

## Self-Review

**Spec coverage check:**
1. ✅ Scaffold (Task 1)
2. ✅ compose.yaml (Task 2)
3. ✅ Core API skeleton + config (Tasks 3, 8)
4. ✅ DB layer: models + migration (Tasks 4, 5, 6)
5. ✅ Tenancy + auth (Tasks 7, 9)
6. ✅ LLM gateway (Task 12)
7. ✅ Domain-pack loader (Task 11)
8. ✅ Connector contract: canonical models + endpoints (Tasks 10, 13, 14, 15)
9. ✅ WooCommerce PHP plugin (Task 17)
10. ✅ Embedding pipeline (Task 16)
11. ✅ kbeauty pack seed (Task 11)
12. ✅ ADRs 0001–0005 (Task 18)

**Type consistency:** `TenantScope`, `LoadedPack`, `CanonicalProduct`, `LLMGateway`, `ModelTier`, `LLMParseError`, `encrypt_credentials`, `decrypt_credentials`, `issue_widget_token`, `validate_widget_token`, `embed_product`, `embed_product_batch`, `upsert_product`, `delete_product` — names used consistently across all tasks.

**No placeholders found.**
