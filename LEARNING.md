# LEARNING.md — The Helix Encyclopedia

> **Who this is for.** Anyone who wants to understand this project deeply enough to have
> built it themselves — developer, future maintainer, or a curious self. Written as a
> mentor would teach it: in the order you'd actually learn it, explaining the *why* behind
> every decision, defining every term, and showing exactly how each concept appears in the
> real code. Read it alongside the source; every section points to where the concept lives.
>
> **How to use it.** Don't binge it. Each module is self-contained. Take one, study the
> concept, look at the real code it points to, then do the "Build it yourself" exercise in
> a scratch repo before moving on. You learn by building the smaller thing first.

---

## Table of Contents

1. [The Mental Model](#module-0--the-mental-model)
2. [HTTP, FastAPI & REST](#module-1--http-fastapi--rest)
3. [Pydantic — Data Validation](#module-2--pydantic--data-validation)
4. [PostgreSQL & Relational Modeling](#module-3--postgresql--relational-modeling)
5. [SQLAlchemy 2.0 — The ORM](#module-4--sqlalchemy-20--the-orm)
6. [Alembic — Database Migrations](#module-5--alembic--database-migrations)
7. [Multi-Tenancy — Architecture & Enforcement](#module-6--multi-tenancy--architecture--enforcement)
8. [Authentication & Security](#module-7--authentication--security)
9. [The Connector Contract](#module-8--the-connector-contract)
10. [Embeddings & Semantic Search with pgvector](#module-9--embeddings--semantic-search-with-pgvector)
11. [Redis — Cache, Rate Limiting & Quota](#module-10--redis--cache-rate-limiting--quota)
12. [Celery — Background Tasks](#module-11--celery--background-tasks)
13. [The LLM Gateway — Claude in Production](#module-12--the-llm-gateway--claude-in-production)
14. [The 4-Layer Routing Architecture](#module-13--the-4-layer-routing-architecture)
15. [Retrieval-Augmented Generation (RAG)](#module-14--retrieval-augmented-generation-rag)
16. [Domain Packs — Vertical-Agnostic Design](#module-15--domain-packs--vertical-agnostic-design)
17. [Webhooks — WooCommerce & Shopify](#module-16--webhooks--woocommerce--shopify)
18. [Conversation History & Multi-Turn Chat](#module-17--conversation-history--multi-turn-chat)
19. [Streaming with Server-Sent Events (SSE)](#module-18--streaming-with-server-sent-events-sse)
20. [AI Content Generation Pipeline](#module-19--ai-content-generation-pipeline)
21. [FastAPI Middleware Deep Dive](#module-20--fastapi-middleware-deep-dive)
22. [Testing Philosophy & Patterns](#module-21--testing-philosophy--patterns)
23. [Docker & Infrastructure](#module-22--docker--infrastructure)
24. [The Full API Surface](#module-23--the-full-api-surface)
25. [Architecture Decisions Record](#module-24--architecture-decisions-record)
26. [Glossary — Every Term Defined](#glossary)
27. [Build It From Scratch Roadmap](#build-it-from-scratch-roadmap)

---

## Module 0 — The Mental Model

Before any code, internalize three ideas. Everything else is detail.

### 1. The intelligence is a service, not a plugin

A plugin sits inside someone's store. A service runs on your own servers and stores talk
to it over HTTP. Helix chose the service model because:

- Shopify forbids running real backend logic in-store — their apps live in iframes and
  call an external API
- AI features need secrets (API keys), a database, and background workers — none of which
  a plugin can host safely
- One service can serve thousands of stores across multiple verticals while a plugin is
  forever coupled to one store's platform

**Mental model:** the store is a thin client (it handles checkout and inventory); Helix is
the brain (it handles intelligence, recommendations, and generated content).

### 2. Three things vary; everything else is shared

| What varies | How Helix isolates it |
|---|---|
| The e-commerce *platform* (WooCommerce vs Shopify) | **Connector Contract** — canonical models, each connector translates in |
| The *vertical* (K-beauty vs auto parts) | **Domain Pack** — YAML config loaded at runtime |
| The *AI model* (cheap classifier vs powerful generator) | **LLM Gateway** — one interface, three tiers |

Good architecture here is mostly: *draw these three boundaries and never let them leak.*
If the core service ever contains `if platform == "shopify"` or `if skin_type`, a
boundary has been violated.

### 3. Multi-tenancy is a security property, not a feature

Many merchants share one database. The catastrophic bug is Merchant A seeing Merchant B's
data. Helix enforces isolation at the **data access layer** — every query has a
`WHERE tenant_id = ?` clause, added in the CRUD function, not politely requested higher up.

---

## Module 1 — HTTP, FastAPI & REST

### What is HTTP?

**HTTP** (HyperText Transfer Protocol) is the language computers use to ask for and send
data over the web. Every API call in Helix is an HTTP request.

**Key concepts:**

| Concept | Explanation |
|---|---|
| **Method/Verb** | `GET` = read. `POST` = create/trigger. `PATCH` = partial update. `DELETE` = remove. |
| **Status code** | 3-digit number indicating outcome. `200` OK, `201` Created, `202` Accepted (queued), `401` Unauthorized, `404` Not Found, `409` Conflict, `422` Validation Error, `429` Rate Limited, `500` Server Error. |
| **Header** | Key-value metadata on the request or response. Helix uses `X-Helix-Tenant-Key` for merchant auth, `Authorization: Bearer <token>` for widget auth, `X-Request-Id` for correlation. |
| **Body** | JSON payload. Validated by Pydantic before the handler ever runs. |
| **Idempotency** | A `GET` is always safe to retry. A `POST` may create a duplicate if retried — design to handle this (e.g., upsert with a unique constraint). |

### Why FastAPI?

FastAPI is a Python web framework that generates routes, validates request/response types
with Pydantic, and produces automatic API documentation — with very little boilerplate.

```python
# services/core/helix/api/app.py
from fastapi import FastAPI

app = FastAPI(title="helix", version="0.1.0", docs_url="/docs")
```

The `/docs` endpoint generates an interactive Swagger UI automatically from your type
annotations — every Pydantic model becomes a documented schema.

### Routers — organizing endpoints

Rather than registering every route on `app` directly, Helix groups related endpoints into
`APIRouter` objects that are registered in `app.py`:

```python
# In helix/api/routers/search.py
router = APIRouter(prefix="/v1/search", tags=["search"])

@router.get("/products")
async def search_products(...): ...

# In app.py
from helix.api.routers import search
app.include_router(search.router)
```

The prefix means `GET /v1/search/products` — the router prefix + the route path.

**Route ordering matters.** FastAPI matches routes top-to-bottom. If you register
`/products/{id}` before `/products/bulk-generate`, then a request for
`/products/bulk-generate` would match `{id}` with value `bulk-generate`. Always register
literal routes before path-parameter catch-alls.

### Async handlers

All Helix route handlers are `async def`. This lets FastAPI use asyncio to handle
thousands of concurrent requests without blocking on I/O (database queries, API calls).

```python
@router.get("/products", response_model=SearchResponse)
async def search_products(q: str, ...) -> SearchResponse:
    # awaiting here doesn't block other requests
    result = await vector_search_products(db, tenant.id, ...)
    return SearchResponse(...)
```

**`response_model`** tells FastAPI what shape the response should be — it validates and
serializes the return value to JSON, and documents it in Swagger.

### `create_app` factory pattern

Helix uses a factory function rather than a module-level `app = FastAPI()`. This lets
tests create fresh app instances with custom settings:

```python
# helix/api/app.py
def create_app(settings: Settings | None = None) -> FastAPI:
    s = settings or get_settings()
    app = FastAPI(...)
    # register all routers
    # add middleware
    return app

app = create_app()  # module-level for production server
```

**Build it yourself:** write a FastAPI app with one `POST /echo` that validates a Pydantic
body and returns it, and one `GET /health` that returns `{"status": "ok"}`. Add a router
for each group.

---

## Module 2 — Pydantic — Data Validation

**Pydantic** is the validation library that powers both FastAPI's request/response
parsing and Helix's settings system.

### BaseModel

Every Pydantic model inherits from `BaseModel`. Fields are Python type annotations:

```python
from pydantic import BaseModel

class ChatRequest(BaseModel):
    query: str
    customer_id: str | None = None   # optional, defaults to None
    customer_profile: dict = {}      # optional, defaults to empty dict
    conversation_id: str | None = None
```

When FastAPI receives a `POST /v1/widget/chat`, it:
1. Reads the JSON body
2. Tries to construct `ChatRequest` from it
3. If validation fails → automatic `422 Unprocessable Entity` response
4. If it succeeds → passes the validated object to the handler

### Field validation

```python
from pydantic import BaseModel
from typing import Annotated
from fastapi import Query

# Query parameters with constraints
limit: Annotated[int, Query(ge=1, le=100)] = 20  # ge=greater-or-equal, le=less-or-equal
offset: Annotated[int, Query(ge=0)] = 0
```

### `model_dump(exclude_unset=True)` — PATCH semantics

For PATCH endpoints, you only want to update fields the client explicitly sent, not
overwrite everything with defaults:

```python
class ProductUpdate(BaseModel):
    title: str | None = None
    in_stock: bool | None = None
    price_minor: int | None = None

body = ProductUpdate(title="New Name")  # only title was sent
body.model_dump()                        # {"title": "New Name", "in_stock": None, "price_minor": None}
body.model_dump(exclude_unset=True)     # {"title": "New Name"} — only what was actually sent
```

`exclude_unset=True` is the correct semantic for PATCH. `exclude_none=True` is wrong — it
would drop `in_stock: false` (a valid explicit value) because `False` is falsy.

### SecretStr

```python
from pydantic import SecretStr

class Settings(BaseSettings):
    anthropic_api_key: SecretStr
```

`SecretStr` wraps sensitive values so they never appear in `repr()`, logs, or Pydantic's
JSON serialization by accident. You access the value with `.get_secret_value()`.

### `pydantic_settings` — configuration from environment

```python
from pydantic_settings import BaseSettings, SettingsConfigDict

class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8")

    database_url: PostgresDsn
    anthropic_api_key: SecretStr
    default_monthly_query_limit: int = 10_000
```

Pydantic reads values from environment variables (or `.env` file). `PostgresDsn` is a
special type that validates and parses a PostgreSQL connection string.

**`@lru_cache` on `get_settings()`** means the Settings object is created once and
reused — no re-reading the environment on every request:

```python
@lru_cache
def get_settings() -> Settings:
    return Settings()
```

---

## Module 3 — PostgreSQL & Relational Modeling

### Why PostgreSQL?

PostgreSQL is a mature, open-source relational database. Helix chose it because:
- It's reliable and well-understood
- The `pgvector` extension adds vector/embedding support, avoiding a second database
- JSONB columns handle the parts of the data that vary per vertical
- It supports full transactions, foreign keys, and all the safety guarantees you need

### Relational modeling fundamentals

**Table:** a named collection of rows, each row having the same columns.

**Primary key (PK):** a column (or combination) that uniquely identifies each row. Helix
uses UUIDs as PKs (`uuid4()`) rather than sequential integers — UUIDs don't leak count
information and are safe to generate without coordination.

**Foreign key (FK):** a column in one table that references the PK of another. Enforces
referential integrity. Example: `Product.tenant_id` references `Tenant.id`.

```sql
-- Every product row belongs to exactly one tenant
FOREIGN KEY (tenant_id) REFERENCES tenant(id) ON DELETE CASCADE
```

`ON DELETE CASCADE` means: if a Tenant row is deleted, all its Products are
automatically deleted too — no orphaned data.

**Unique constraint:** enforces that a combination of columns is unique.

```python
# Every product has a unique (tenant_id, platform_id) pair
UniqueConstraint("tenant_id", "platform_id", name="uq_product_tenant_platform")
```

This makes upserts safe — you can try to insert and on conflict update instead.

**Index:** a data structure that speeds up lookups on a column. Without an index,
PostgreSQL scans every row. With one, it jumps directly to matching rows.

```python
# Helix adds an index on every FK column so lookups by tenant_id are fast
tenant_id: Mapped[UUID] = mapped_column(ForeignKey("tenant.id"), index=True)
```

### JSONB — JSON in Postgres

Helix uses two JSONB columns:

| Column | Why JSON | Why not a separate table |
|---|---|---|
| `Product.domain_attributes` | K-beauty product has `spf`, `skin_type`, `ingredients`. Auto parts has `make`, `model`, `year`. The shape varies per vertical. | Would require a different schema per vertical. |
| `Product.categories` | A product has zero or more categories (array). | Would need a junction table for a simple list. |
| `Customer.profile` | Customer preferences vary per vertical (skin type for K-beauty, vehicle for auto parts). | Same reason as domain_attributes. |

JSONB is indexed, queryable, and efficient. The trade-off: no column-level validation,
no FK references into the JSON structure. Use it when the shape varies and you'd
otherwise need schema changes per vertical.

### Money as integer minor units

```python
price_minor: Mapped[int] = mapped_column(Integer, nullable=False)
```

Money is **never** stored as float. `0.1 + 0.2 = 0.30000000000000004` in floating-point.

Helix stores prices as the smallest unit of the currency — **minor units** (cents, pence,
jeon). `2500` means $25.00 USD or £25.00 GBP. Divide by 100 only when displaying.

### Timestamps with timezone

```python
from sqlalchemy.dialects.postgresql import TIMESTAMP

created_at: Mapped[datetime] = mapped_column(TIMESTAMP(timezone=True), nullable=False, default=_now)
```

Always store timestamps **with timezone** (`TIMESTAMP WITH TIME ZONE` in SQL,
`TIMESTAMP(timezone=True)` in SQLAlchemy). This stores UTC internally and is unambiguous.
Never store local time in the database — timezones change and you'll get bugs.

---

## Module 4 — SQLAlchemy 2.0 — The ORM

An **ORM** (Object-Relational Mapper) lets you work with database rows as Python objects
instead of writing raw SQL. Helix uses SQLAlchemy 2.0 with the modern `Mapped[]` style.

### Declarative models

```python
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column

class Base(DeclarativeBase):
    pass

class Tenant(Base):
    __tablename__ = "tenant"

    id: Mapped[UUID] = mapped_column(primary_key=True, default=uuid4)
    name: Mapped[str] = mapped_column(String, nullable=False)
    credentials_enc: Mapped[bytes] = mapped_column(LargeBinary, nullable=False)
    public_key: Mapped[UUID] = mapped_column(unique=True, default=uuid4)
    pack_id: Mapped[Optional[str]] = mapped_column(String, nullable=True)
```

`Mapped[T]` is the SQLAlchemy 2.0 way of annotating a column's Python type. The ORM
derives the column type from the Python type automatically in many cases.

### Async sessions

Helix uses `AsyncSession` for all database operations — non-blocking I/O compatible with
FastAPI's async handlers:

```python
# helix/db/engine.py
from sqlalchemy.ext.asyncio import create_async_engine, async_sessionmaker

engine = create_async_engine(settings.database_url_async)
async_session_factory = async_sessionmaker(engine, expire_on_commit=False)
```

**`expire_on_commit=False`** means that after `await session.commit()`, SQLAlchemy
doesn't immediately expire all loaded objects (forcing a re-fetch). This is important for
async code where you often need to access attributes after committing.

### The CRUD pattern

Helix puts all database access in `helix/db/crud/` — thin functions that take a session
and return domain objects. No SQL leaks into routers or business logic.

```python
# helix/db/crud/products.py
async def get_product_by_id(
    session: AsyncSession,
    tenant_id: UUID,
    product_id: UUID,
) -> Product | None:
    result = await session.execute(
        select(Product).where(
            Product.tenant_id == tenant_id,  # ALWAYS filter by tenant
            Product.id == product_id,
        )
    )
    return result.scalar_one_or_none()
```

Key patterns:
- `scalar_one_or_none()` — returns one object or `None`. Raises if multiple rows match.
- `scalar_one()` — returns exactly one, raises if zero or multiple.
- `scalars().all()` — returns a list. Wrap in `list()` for type clarity.
- `select(Model)` — build a SELECT query.
- `where(*filters)` — pass multiple conditions.

### The upsert pattern

When you want "insert if not exists, update if it does," use PostgreSQL's `ON CONFLICT`:

```python
from sqlalchemy.dialects.postgresql import insert

stmt = (
    insert(Product)
    .values(id=product.id, tenant_id=product.tenant_id, title=product.title, ...)
    .on_conflict_do_update(
        constraint="uq_product_tenant_platform",
        set_=dict(title=product.title, ...),
    )
    .returning(Product)
)
result = await session.execute(stmt)
product = result.scalar_one()
```

The `UniqueConstraint` on `(tenant_id, platform_id)` is the conflict target — if a
product with the same (tenant, platform_id) exists, update it; otherwise insert.

### Session lifecycle in FastAPI

```python
# helix/api/deps.py
async def get_db() -> AsyncGenerator[AsyncSession, None]:
    async with async_session_factory() as session:
        yield session
```

This is a FastAPI dependency. The `async with` context manager opens a session at the
start of the request and closes it (with rollback if an exception occurred) at the end.
Routes inject it with `db: AsyncSession = Depends(get_db)`.

### `flush` vs `commit`

| Operation | What it does |
|---|---|
| `await session.flush()` | Sends SQL to the database but doesn't commit the transaction. The row is visible within the same session but not to other connections. Useful to get a generated ID. |
| `await session.commit()` | Makes all changes permanent and visible to all connections. |
| `await session.refresh(obj)` | Re-fetches an object's columns from the database. Needed after flush to get server-generated defaults. |

In Helix, CRUD functions flush+refresh; the router commits:

```python
# CRUD function
session.add(draft)
await session.flush()    # sends INSERT, gets generated id
await session.refresh(draft)  # loads server-default columns
return draft

# Router
draft = await approve_content_draft(db, draft)
await db.commit()  # makes it permanent
```

---

## Module 5 — Alembic — Database Migrations

**Alembic** is the migration tool for SQLAlchemy. When you change a model (add a column,
create a table), you write a migration script that transforms the live database to match.

### Why migrations?

Without migrations, you'd have to manually run `ALTER TABLE` SQL every time you deploy.
Migrations are versioned, tracked in git, and applied in order — the database evolves
with the code.

### Migration structure

```
helix/db/migrations/
  env.py          — tells Alembic how to connect
  versions/
    0001_initial.py
    0002_tenant_pack_id.py
    0003_conversation.py
    0004_content_draft.py
```

Each migration has:
- `revision` — unique identifier
- `down_revision` — the previous migration (forms a linked list)
- `upgrade()` — how to apply the change
- `downgrade()` — how to reverse it

```python
# 0004_content_draft.py
revision = "0004"
down_revision = "0003"

def upgrade() -> None:
    op.create_table(
        "content_draft",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("tenant_id", postgresql.UUID(as_uuid=True), nullable=False),
        ...
        sa.UniqueConstraint("tenant_id", "product_id", "field",
                            name="uq_content_draft_tenant_product_field"),
    )
    op.create_index("ix_content_draft_tenant_id", "content_draft", ["tenant_id"])

def downgrade() -> None:
    op.drop_table("content_draft")
```

**Apply migrations:** `alembic upgrade head` runs all pending migrations in order.

---

## Module 6 — Multi-Tenancy — Architecture & Enforcement

### What is multi-tenancy?

One running application serves multiple independent customers (**tenants**). Each tenant's
data is completely isolated from others. The alternative — one app instance per tenant —
is wasteful and impossible to operate at scale.

### The Tenant model

```python
class Tenant(Base):
    id: Mapped[UUID]           # internal UUID
    name: Mapped[str]          # "Jane's K-beauty Store"
    platform: Mapped[str]      # "woocommerce" or "shopify"
    store_url: Mapped[str]     # "https://janes-store.com"
    credentials_enc: Mapped[bytes]  # Fernet-encrypted API keys — NEVER in API responses
    public_key: Mapped[UUID]   # what the merchant puts in their plugin config
    pack_id: Mapped[Optional[str]]  # "kbeauty" or None (uses default)
```

**`credentials_enc`** is a hard security rule: this column must **never** appear in any
API response. It holds the store's WooCommerce/Shopify API keys, encrypted at rest.

**`public_key`** is what merchants use to authenticate — a UUID they put in their plugin
settings. It's public (not secret) because it only identifies which tenant you are, not
proves you have admin access.

### Enforcement at the data layer

Every CRUD function takes `tenant_id` as a parameter and adds it to every query:

```python
# RIGHT — always scoped
async def get_product_by_id(session, tenant_id: UUID, product_id: UUID):
    return await session.scalar(
        select(Product).where(
            Product.tenant_id == tenant_id,  # ← this is the enforcement
            Product.id == product_id,
        )
    )

# WRONG — missing tenant scope, this is a data breach
async def get_product_by_id_WRONG(session, product_id: UUID):
    return await session.scalar(select(Product).where(Product.id == product_id))
```

The second version could return products belonging to any tenant. This is why isolation
is enforced at the CRUD layer — not as a reminder in the router.

### Provisioning a tenant

```
POST /v1/tenants/provision
Headers: X-Helix-Provision-Key: <admin secret>
Body: { "name": "...", "platform": "woocommerce", "store_url": "...", "credentials": {...} }
```

The `_auth_provision` dependency verifies the provision key (a shared secret between Helix
operators and their clients) before allowing tenant creation.

---

## Module 7 — Authentication & Security

Helix has three distinct authentication systems for three different callers:

| Caller | Auth mechanism | How |
|---|---|---|
| **Helix operators** (admin) | Provision key | `X-Helix-Provision-Key` header, bcrypt-checked against env var |
| **Merchants** (API) | Tenant public key | `X-Helix-Tenant-Key` header, looked up in `tenant.public_key` |
| **Shoppers** (widget) | Short-lived JWT | `Authorization: Bearer <token>`, issued by `/v1/widget/session` |

### Merchant authentication (`get_tenant`)

```python
# helix/api/deps.py
async def get_tenant(
    x_helix_tenant_key: Annotated[str | None, Header()] = None,
    db: AsyncSession = Depends(get_db),
) -> Tenant:
    if x_helix_tenant_key is None:
        raise HTTPException(status_code=401, detail="Missing tenant key")
    key_uuid = UUID(x_helix_tenant_key)  # validates UUID format
    tenant = await get_tenant_by_public_key(db, key_uuid)
    if tenant is None:
        raise HTTPException(status_code=401, detail="Unknown tenant key")
    return tenant
```

This is a **FastAPI dependency** — any route that declares `tenant: Tenant = Depends(get_tenant)`
automatically gets this check for free.

### Widget JWT authentication (`get_widget_tenant`)

```python
async def get_widget_tenant(
    authorization: Annotated[str | None, Header()] = None,
    db: AsyncSession = Depends(get_db),
) -> Tenant:
    token = authorization.removeprefix("Bearer ")
    tenant_id = validate_widget_token(token, settings.session_secret.get_secret_value())
    tenant = await get_tenant_by_id(db, tenant_id)
    return tenant
```

**JWT (JSON Web Token):** a self-contained signed token. The signature is verified with
`session_secret` — if someone tampers with the token, the signature won't match.

**Why short-lived?** The widget token expires in 15 minutes. If it leaks (browser cache,
network sniff), the window of abuse is tiny. The widget refreshes it silently.

**Why no secrets in the widget token?** The token is visible in the browser. It carries
only `tenant_id` — a UUID that identifies which store, not any credentials to the store.
The principle of least privilege: give the token exactly the power it needs, no more.

### Encryption at rest (Fernet)

Merchant API credentials (WooCommerce consumer key/secret, Shopify access token) are
sensitive. Storing them in plaintext in the database is a catastrophic breach risk.

**Fernet** is a symmetric encryption scheme from the `cryptography` library.

```python
from cryptography.fernet import Fernet

key = Fernet.generate_key()     # done once, stored in env var as CREDENTIAL_ENCRYPTION_KEY
f = Fernet(key)

# Encrypting before storage
ciphertext = f.encrypt(json.dumps(credentials).encode())   # → bytes stored in credentials_enc

# Decrypting when needed (sync endpoint, webhook handler)
plaintext = f.decrypt(tenant.credentials_enc)
credentials = json.loads(plaintext)
```

The key lives only in the environment — never in the database or code.

### HMAC webhook verification

When WooCommerce or Shopify sends a webhook, you must verify it came from them, not an
attacker. Both use HMAC-SHA256:

```python
import hmac, hashlib

def verify_shopify_hmac(payload: bytes, signature: str, secret: str) -> bool:
    expected = hmac.new(secret.encode(), payload, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature)
```

`hmac.compare_digest` is constant-time comparison — avoids timing attacks where an
attacker could deduce the secret by measuring how long the comparison takes.

---

## Module 8 — The Connector Contract

### The problem

WooCommerce returns products like this:
```json
{ "id": 123, "name": "COSRX Snail Cream", "price": "25.00", "stock_status": "instock" }
```

Shopify returns them like this:
```json
{ "id": "gid://shopify/Product/456", "title": "COSRX Snail Cream", "variants": [{"price": "25.00"}], "published_at": "..." }
```

If the core service had to understand both formats, every new platform would mean
rewriting half the core.

### The solution: canonical models

Each connector translates its platform's format into a **canonical model** — a single
agreed-upon shape:

```python
# helix/connectors/models.py
class CanonicalProduct(BaseModel):
    platform_id: str        # original ID from the platform
    title: str
    description_html: str | None
    price_minor: int        # always integer minor units
    currency: str           # always "USD", "ZAR", etc.
    images: list[str]
    categories: list[str]
    in_stock: bool
    domain_attributes: dict  # vertical-specific extras
```

The WooCommerce connector maps `"25.00"` → `2500`, `"instock"` → `True`.
The Shopify connector maps `variants[0].price` → `2500`.

**The core never knows which platform sent the data.** This is the adapter pattern — two
different interfaces, one unified view.

### Sync endpoints

```
POST /v1/sync/products   — receives a CanonicalProduct batch from the connector
POST /v1/sync/customers  — receives CanonicalCustomer batch
POST /v1/sync/orders     — receives CanonicalOrder batch
```

The PHP connector plugins (WooCommerce, Shopify) call these endpoints after translating.

---

## Module 9 — Embeddings & Semantic Search with pgvector

### What is an embedding?

An **embedding** is a fixed-size list of numbers (a vector) that represents the meaning
of a piece of text. Two texts with similar meaning will have vectors that are close
together in high-dimensional space.

Example: `"oily skin moisturizer"` and `"hydrating cream for greasy skin"` will have very
similar vectors even though they share almost no words.

### Why 1024 dimensions?

Helix uses **Voyage AI's `voyage-3-lite` model** to generate embeddings. It outputs
vectors with 1024 floating-point numbers. Higher dimensions = more expressive but more
storage.

```python
# helix/db/models.py
from pgvector.sqlalchemy import Vector

embedding: Mapped[Optional[list[float]]] = mapped_column(Vector(1024), nullable=True)
```

`pgvector` extends PostgreSQL to store and query vectors efficiently.

### Generating embeddings (Celery task)

Embedding is slow (network call to Voyage AI), so it runs in a Celery background task:

```python
# helix/workers/tasks/embedding.py
@celery_app.task(bind=True, max_retries=3, default_retry_delay=60,
                 name="helix.workers.tasks.embedding.embed_product")
def embed_product(self, tenant_id_str: str, product_id_str: str) -> None:
    asyncio.run(_embed_async(tenant_id_str, product_id_str))
```

### Similarity search with pgvector

```python
# helix/db/crud/products.py
distance_col = Product.embedding.cosine_distance(query_vector).label("distance")
result = await session.execute(
    select(Product, distance_col)
    .where(Product.tenant_id == tenant_id, Product.embedding.is_not(None))
    .order_by(distance_col)   # smallest distance = most similar
    .limit(limit)
)
# Convert distance to similarity score: 1.0 = identical, 0.0 = opposite
return [(row.Product, 1.0 - row.distance) for row in result]
```

**Cosine distance** measures the angle between two vectors. `distance = 0` means the
vectors point in exactly the same direction (identical meaning). `distance = 2` means
opposite meaning. We subtract from 1.0 to get an intuitive **similarity score**.

### The search pipeline

```
User query: "something for damaged moisture barrier"
         ↓
embed_query(query, settings)   → 1024-dim vector via Voyage AI
         ↓
vector_search_products(db, tenant_id, query_vector, limit=5)   → Top 5 products by cosine similarity
         ↓
Return products with similarity scores
```

**Build it yourself:** embed 50 product descriptions, store the vectors in a list,
compute cosine similarity manually (dot product / magnitude product), and find the nearest
5 to a query vector. You now understand everything pgvector does.

---

## Module 10 — Redis — Cache, Rate Limiting & Quota

**Redis** is an in-memory data store — reads and writes are microseconds, compared to
milliseconds for PostgreSQL. Helix uses it for three distinct purposes.

### 1. LLM response cache

```python
# helix/llm/cache.py — key structure
key = sha256(f"{model}:{system}:{user}".encode()).hexdigest()
```

If the same (model, system prompt, user query) combination is seen again within 24 hours,
return the cached response instead of calling Claude. This:
- Eliminates duplicate cost for repeated queries
- Reduces latency to microseconds for cached responses
- Is safe because LLM responses are deterministic for identical inputs

```python
await cache.set(model, system, user, result_json, ttl=86400)  # 24 hours
cached = await cache.get(model, system, user)
```

### 2. Rate limiting (sliding window)

```python
# helix/api/middleware/rate_limit.py
# 30 requests per 60 seconds per tenant on widget endpoints
key = f"rl:{tenant_id}:{window_start}"
count = await redis.incr(key)
await redis.expire(key, 60)
if count > 30:
    return 429 Too Many Requests
```

**Sliding window:** uses the current 60-second window. A new window key every 60 seconds.
**Fails open:** if Redis is unreachable, the middleware lets the request through — availability
trumps rate limiting for a real user.

### 3. Monthly quota tracking

```python
# helix/api/middleware/quota.py
key = f"quota:{tenant_id}:{YYYY-MM}"
count = await redis.incr(key)          # atomic increment
await redis.expire(key, 32*24*3600)   # 32 days TTL (covers month overlap)
if count > settings.default_monthly_query_limit:
    return 429 with X-Quota-Exceeded header
```

**`INCR` is atomic.** Redis processes commands one at a time. No race condition — two
concurrent requests won't both read `999`, increment to `1000`, and both think they're
under the limit.

**Key pattern `quota:{tenant_id}:{YYYY-MM}`:** Each tenant has one counter per calendar
month. The dashboard can read it to show quota remaining.

### Admin quota reset

```python
# POST /v1/admin/tenants/{id}/quota/reset
r = aioredis.from_url(str(settings.redis_url), decode_responses=True)
try:
    key = f"quota:{tenant_id}:{YYYY-MM}"
    deleted = await r.delete(key)   # 1 if existed, 0 if not
    return QuotaResetResponse(reset=deleted > 0, key=key)
finally:
    await r.aclose()   # always close the connection
```

`decode_responses=True` means Redis returns Python `str` not `bytes`. Always include it.
Always close the connection in `finally` to avoid connection leaks.

---

## Module 11 — Celery — Background Tasks

### Why background tasks?

Some operations are too slow to run synchronously during an HTTP request:
- Embedding 5,000 products (hundreds of API calls)
- Generating AI descriptions (LLM calls are slow)
- Syncing a full catalog

These go onto a **task queue** — the HTTP handler enqueues the task and returns immediately
(202 Accepted), and a **worker process** picks up and executes the task asynchronously.

### Architecture

```
HTTP Handler ─→ task.delay(args) ─→ Redis (broker) ─→ Celery Worker ─→ executes task
                                                    ↑
                                              (queue storage)
```

**Broker:** Redis stores the serialized task until a worker picks it up.
**Worker:** a separate Python process running `celery worker`.

### Task definition

```python
# helix/workers/tasks/content.py
from helix.workers.celery_app import celery_app

@celery_app.task(
    bind=True,          # gives access to `self` (the task instance)
    max_retries=3,      # retry up to 3 times on failure
    default_retry_delay=60,  # wait 60 seconds between retries
    name="helix.workers.tasks.content.generate_description",
)
def generate_description(self, tenant_id_str: str, product_id_str: str) -> None:
    try:
        asyncio.run(_generate_async(tenant_id_str, product_id_str))
    except LLMParseError as exc:
        # Parse failures won't self-heal on retry — log and drop
        logger.error("generate_description_parse_failure", error=str(exc))
    except Exception as exc:
        raise self.retry(exc=exc)   # retry with exponential backoff
```

**`bind=True`** gives `self` — needed to call `self.retry()`.

**`asyncio.run()`:** Celery tasks are synchronous functions (Celery workers are not async).
To use async code (SQLAlchemy, httpx), wrap it in `asyncio.run()`.

### Firing a task

```python
# Fire and forget — returns immediately
generate_description.delay(str(tenant.id), str(product_id))
```

Always pass UUIDs as strings — Celery serializes arguments to JSON, and UUIDs aren't
JSON-serializable by default.

### Idempotency — design for retry

A task may run more than once (network failure, worker crash). Design tasks to be safe
when run twice:

- The `upsert_content_draft` CRUD deletes then inserts — running it twice produces the
  same result (idempotent)
- The embedding task calls `upsert_product` which uses `ON CONFLICT DO UPDATE` — safe
  to retry

### When NOT to retry

Some failures won't self-heal:
```python
except LLMParseError as exc:
    logger.error("parse_failure")  # log and drop — don't retry
```

If Claude returned malformed JSON and a repair attempt failed, retrying won't change the
model's output. Drop the task and let an operator investigate.

---

## Module 12 — The LLM Gateway — Claude in Production

### Why a gateway?

If every feature calls the Anthropic SDK directly, you get:
- Inconsistent prompts scattered across the codebase
- No cost visibility (who used how much?)
- Brittle JSON parsing (what if the model returns prose instead of JSON?)
- Hard-coded model choices everywhere

The `LLMGateway` centralizes all of this:

```python
# Any feature uses this one interface
gateway = LLMGateway(settings, tenant_id)
result = await gateway.complete(
    tier=ModelTier.GENERATE,
    system="You are a product copywriter...",
    user="Write a description for: COSRX Snail Cream...",
    response_schema=DescriptionDraft,  # Pydantic model
    max_tokens=2048,
)
# result is a validated DescriptionDraft instance
```

### Model tiers

| Tier | Model | Cost | When to use |
|---|---|---|---|
| `CLASSIFY` | `claude-haiku-4-5` | $1/$5 per M tokens | Intent classification, cheap high-volume decisions |
| `GENERATE` | `claude-sonnet-4-6` | $3/$15 per M tokens | Product descriptions, consultant answers |
| `REASON` | `claude-opus-4-8` | $5/$25 per M tokens | Complex multi-step reasoning, reserved for future |

Using Haiku for a binary intent classification (is this a product search or a FAQ?) and
Sonnet for full answer generation cuts cost by ~67% per query.

### Structured output — forcing JSON

The model is instructed to return JSON and given the schema:

```python
schema_hint = json.dumps(response_schema.model_json_schema(), indent=2)
user_with_schema = f"{user}\n\nRespond with only valid JSON that matches this schema:\n{schema_hint}"
```

### Parse and repair loop

```python
raw = message.content[0].text
result = self._parse(raw, response_schema)
if result is None:
    # One repair attempt: show the model what it said and ask it to fix it
    repair_msg = await client.messages.create(
        messages=[
            *message_history,
            {"role": "user", "content": user_with_schema},
            {"role": "assistant", "content": raw},
            {"role": "user", "content": "Your response was not valid JSON. Return only the JSON object, nothing else."},
        ]
    )
    result = self._parse(repair_msg.content[0].text, response_schema)
    if result is None:
        raise LLMParseError(...)
```

Attempt 1: call Claude, parse output.
Attempt 2 (if parse fails): show Claude what it said, ask it to correct.
If still fails: raise `LLMParseError` and handle upstream.

### Usage metering

```python
def _log_usage(self, message, model_id, call_type) -> None:
    in_tokens = message.usage.input_tokens
    out_tokens = message.usage.output_tokens
    in_cost, out_cost = _COSTS.get(model_id, (0.0, 0.0))
    cost_usd = (in_tokens * in_cost + out_tokens * out_cost) / 1_000_000
    self._last_usage["cost_usd"] += cost_usd
    # Prices per million tokens, so divide by 1M
```

After each call, the gateway accumulates token counts and cost in `self._last_usage`.
The router reads `result.cost_usd` and writes a `UsageEvent` row — per-tenant cost
tracking for billing and quota enforcement.

### Prompt caching

```python
system=[{"type": "text", "text": system, "cache_control": {"type": "ephemeral"}}]
```

Anthropic's **prompt caching** marks the system prompt as cacheable. On repeated calls
with the same system prompt, Anthropic reuses the prefilled prompt from their cache —
reducing cost and latency. The `ephemeral` cache type lasts 5 minutes.

---

## Module 13 — The 4-Layer Routing Architecture

The widget chat pipeline doesn't jump straight to Claude. Cheap, fast answers come first:

```
User query
    │
    ▼
Layer 1: Vector Search ── retrieve relevant products
    │
    ▼
Layer 2: Template Layer ── exact FAQ match from pack
    │ (answered? return immediately)
    ▼
Layer 3: Rule Engine Layer ── compatibility rules from pack
    │ (answered? return immediately)
    ▼
Layer 4: LLM (Claude) ── grounded answer with retrieved products
```

### Layer 1: Vector Search

Not a decision layer — just retrieves context products:

```python
query_vector = await embed_query(body.query, settings)
product_rows = await vector_search_products(db, tenant.id, query_vector, limit=5)
context_products = [{"title": p.title, ...} for p, _ in product_rows]
```

### Layer 2: Template Layer

Answers known FAQ questions without an LLM call:

```python
# helix/llm/layers.py
class TemplateLayer:
    async def query(self, query_text: str, templates: dict[str, str]) -> LayerResult:
        q = query_text.lower()
        for key, answer in templates.items():
            if key.lower() in q:
                return LayerResult(answered=True, response=answer, confidence=1.0)
        return LayerResult(answered=False)
```

The templates come from the pack's YAML:
```yaml
copy:
  templates:
    "return policy": "We offer 30-day returns on all unopened items."
    "shipping": "Free shipping on orders over R500."
```

If the query contains "return policy" (case-insensitive), return the canned answer
instantly. No API call. Zero cost.

### Layer 3: Rule Engine Layer

Answers compatibility and routine questions from the pack's rules:

```python
class RuleEngineLayer:
    def check_products(self, products_attrs, pack_rules) -> CompatibilityResult:
        from helix.domain.rules import check_compatibility
        return check_compatibility(products_attrs, pack_rules)
```

K-beauty compatibility rules (from pack YAML):
```yaml
rules:
  - type: conflict
    ingredient_a: "retinol"
    ingredient_b: "vitamin_c"
    reason: "Can cause irritation when used together"
  - type: ordering
    step: "toner"
    before: "serum"
```

### Layer 4: LLM (Claude)

Only runs if layers 2 and 3 didn't answer:

```python
grounded_user = (
    f"Customer profile: {customer_profile}\n\n"
    f"Available products:\n{product_list}\n\n"  # only the retrieved products
    f"Customer question: {query}"
)
llm_result = await gateway.complete(
    tier=ModelTier.GENERATE,
    system=system_prompt,   # from pack
    user=grounded_user,
    response_schema=ConsultantResponse,
)
```

The answer must come from the retrieved products — this is RAG. Claude cannot invent
product details because it's only given the actual product data.

### Route results

```python
RouteResult(
    response="...",
    source="template" | "rules" | "llm",  # how was it answered?
    products_referenced=["uuid1", "uuid2"],
    model="claude-sonnet-4-6",
    tokens_in=245, tokens_out=178,
    cost_usd=0.000345,
)
```

The `source` field tells the frontend how the answer was generated — useful for debugging
and analytics.

---

## Module 14 — Retrieval-Augmented Generation (RAG)

**RAG** is the technique of retrieving relevant documents/data before asking the model to
answer — so the model answers from *your* data, not its training data.

### The problem with ungrounded models

Without RAG:
- Model invents product specifications ("this cream contains 10% niacinamide" — wrong)
- Model recommends products that don't exist in the store
- Legal liability for false product claims

### The RAG loop in Helix

```
1. embed_query(user_question) → query_vector
2. vector_search(query_vector) → top 5 relevant products from THIS store
3. Build context: "Here are the products you can recommend from: [product1], [product2]..."
4. Ask Claude: "Answer the customer question using ONLY these products."
5. Claude answers grounded in real product data
```

The key instruction: *use only the provided products*. If none match, Claude says "I
don't see a product for that — here's support." It cannot hallucinate products that
weren't in step 2.

### ConsultantResponse schema

```python
class ConsultantResponse(BaseModel):
    response: str                       # the answer text
    product_ids_referenced: list[str]   # which products were mentioned
```

The `product_ids_referenced` field tracks which products Claude referenced — used for
analytics (`top-referenced products` endpoint) and to show product cards in the widget.

---

## Module 15 — Domain Packs — Vertical-Agnostic Design

### The core problem

The same engine should serve K-beauty stores *and* auto-parts stores without changing the
core code. K-beauty has `skin_type`, `ingredients`, `spf`. Auto parts have `make`,
`model`, `year`, `fitment`.

### What a pack is

A pack is a YAML file declaring everything vertical-specific:

```yaml
# packs/kbeauty/pack.yaml
id: kbeauty
name: K-beauty

product:
  attribute_schema:
    skin_type: string
    spf: integer
    ingredients: array

customer:
  profile_schema:
    skin_type: string
    skin_concerns: array
    allergies: array

routine:
  steps:
    - cleanser
    - toner
    - serum
    - moisturizer
    - sunscreen

rules:
  - type: conflict
    ingredient_a: retinol
    ingredient_b: vitamin_c
    reason: "Can cause irritation when combined"

copy:
  system_prompt: "You are an expert K-beauty consultant..."
  templates:
    "return policy": "We offer 30-day returns on all unopened items."
  description_guidance: "Focus on skin benefits, ingredients, and texture."
```

### Pack loading

```python
# helix/packs/registry.py
_registry: dict[str, LoadedPack] = {}

def load_all_packs(packs_dir: str) -> None:
    for pack_path in Path(packs_dir).iterdir():
        if (pack_path / "pack.yaml").exists():
            pack = PackLoader.load(pack_path)
            _registry[pack.id] = pack

def get_pack_for_tenant(tenant: Tenant) -> LoadedPack:
    pack_id = tenant.pack_id or "kbeauty"
    return _registry.get(pack_id) or default_pack()
```

`tenant.pack_id` allows each tenant to use a different pack. `"kbeauty"` is the default.

### How packs affect features

| Feature | How the pack influences it |
|---|---|
| LLM consultant | `pack.copy.system_prompt` sets the AI's persona |
| Template layer | `pack.copy.templates` provides the FAQ answers |
| Rule engine | `pack.rules` defines compatibility and ordering rules |
| Routine builder | `pack.routine.steps` defines the step order |
| Description generation | `pack.copy.description_guidance` added to the system prompt |
| Customer profile validation | `pack.customer.profile_schema` defines expected fields |

---

## Module 16 — Webhooks — WooCommerce & Shopify

### What is a webhook?

A webhook is a platform calling *you* instead of you calling it. When a product is
updated in WooCommerce, WooCommerce sends a `POST` request to your webhook URL with the
updated product data. You process it in near-real-time.

**Pull (polling):** you call WooCommerce every 5 minutes to check for updates. Slow,
wasteful, always slightly stale.
**Push (webhook):** WooCommerce calls you the moment something changes. Instant, efficient.

### Signature verification (you must always verify)

Anyone on the internet can send a POST to your webhook URL. You must prove it came from
the real platform.

**WooCommerce:** sends `X-WC-Webhook-Signature` header with a base64-encoded HMAC-SHA256
of the body using your shared webhook secret.

**Shopify:** sends `X-Shopify-Hmac-Sha256` header with a base64-encoded HMAC-SHA256 of
the raw body using your shared secret.

```python
import base64, hashlib, hmac

def verify_shopify_hmac(payload: bytes, signature: str, secret: str) -> bool:
    expected = base64.b64encode(
        hmac.new(secret.encode(), payload, hashlib.sha256).digest()
    ).decode()
    return hmac.compare_digest(expected, signature)
```

If verification fails → return `401 Unauthorized`. Never process an unverified webhook.

### Product webhooks

```
POST /v1/webhooks/products         (WooCommerce product.created/updated)
POST /v1/webhooks/shopify/products (Shopify products/create, products/update)
```

Both:
1. Verify signature
2. Translate platform payload → `CanonicalProduct`
3. `upsert_product()` in the database
4. Fire `embed_product.delay()` — async embedding

### Order webhooks

```
POST /v1/webhooks/orders           (WooCommerce order.created)
POST /v1/webhooks/shopify/orders   (Shopify orders/create)
```

Orders track customer purchase history — used for analytics (revenue, top products).

---

## Module 17 — Conversation History & Multi-Turn Chat

### The conversation model

```python
class Conversation(Base):
    id: Mapped[UUID]              # conversation identifier
    tenant_id: Mapped[UUID]       # which store
    customer_id: Mapped[Optional[UUID]]  # which shopper (if known)
    created_at, updated_at: timestamps

class ConversationMessage(Base):
    id: Mapped[UUID]              # message identifier
    conversation_id: Mapped[UUID] # which conversation
    tenant_id: Mapped[UUID]       # for fast tenant-scoped queries
    role: Mapped[str]             # "user" or "assistant"
    content: Mapped[str]          # the message text
    source: Mapped[Optional[str]] # "template" | "rules" | "llm"
    products_referenced: Mapped[list]  # JSONB array of product IDs
    feedback: Mapped[Optional[str]]    # "thumbs_up" | "thumbs_down"
```

### How it works in the pipeline

```python
# helix/api/routers/widget.py — _run_chat_pipeline()

# 1. Find or create conversation
if body.conversation_id:
    conversation = await get_conversation(db, conv_uuid, tenant.id)
if conversation is None:
    conversation = await create_conversation(db, tenant.id, customer_uuid)

# 2. Load last 10 messages as history
prior_messages = await get_messages(db, conversation.id, tenant.id)
conversation_history = [
    {"role": msg.role, "content": msg.content}
    for msg in prior_messages[-10:]
]

# 3. Pass history to LLM (enables follow-up questions)
result = await handle_query(..., conversation_history=conversation_history)

# 4. Persist this turn
_user_msg, assistant_msg = await append_messages(
    db, conversation_id=conversation.id, ...
)
```

The `conversation_history` is passed as `message_history` to the LLM gateway, which
prepends it to the Anthropic API call — Claude sees the full prior context.

**Last 10 messages only** — context windows are finite and expensive. Older turns fall
off but the conversation object stays linked.

### Merchant-facing conversation endpoints

```
GET /v1/conversations?limit=20&offset=0          (list all conversations)
GET /v1/conversations/{id}                        (detail with all messages)
GET /v1/customers/{id}/conversations              (by specific customer)
```

Merchants can review what shoppers asked, see which products were referenced, and read
feedback scores.

---

## Module 18 — Streaming with Server-Sent Events (SSE)

### The problem with waiting

If Claude takes 5 seconds to generate a 300-word response, a plain HTTP request makes
the shopper stare at a blank widget for 5 seconds. **Streaming** starts showing the
response token-by-token as it arrives.

### SSE vs WebSocket

**SSE (Server-Sent Events):** unidirectional server→client push over plain HTTP. Simple,
works everywhere, no special client library.

**WebSocket:** bidirectional, full-duplex. Needed for real-time two-way communication
(multiplayer games, chat where the server initiates). Overkill for a Q&A widget.

Helix uses SSE because the widget only needs to receive tokens — the client sends one
`POST`, the server streams back events.

### Implementation

```python
# helix/api/routers/widget.py
@router.post("/chat/stream")
async def widget_chat_stream(body: ChatRequest, ...) -> StreamingResponse:
    pipeline = await _run_chat_pipeline(body, tenant, db, endpoint)

    async def _events() -> AsyncGenerator[str, None]:
        yield f"data: {json.dumps({'type': 'token', 'content': pipeline.route.response})}\n\n"
        yield f"data: {json.dumps({'type': 'done', 'source': pipeline.route.source, ...})}\n\n"

    return StreamingResponse(_events(), media_type="text/event-stream")
```

**SSE event format:** each event is `data: <json>\n\n` — the `\n\n` marks the end of an
event. The browser's `EventSource` API parses this automatically.

Two events:
1. `{"type": "token", "content": "..."}` — the response text
2. `{"type": "done", "source": "llm", "conversation_id": "..."}` — metadata

(True streaming would yield tokens as they arrive from the Anthropic streaming API. The
current implementation generates the full response then yields it — a future enhancement.)

---

## Module 19 — AI Content Generation Pipeline

### The problem

Product descriptions written by store owners are often thin: "COSRX Snail Mucin Essence.
96% Snail Secretion Filtrate." That doesn't sell. AI can generate rich, SEO-optimised
descriptions from structured product data.

### The pipeline

```
1. Merchant triggers: POST /v1/content/products/{id}/generate → 202 Accepted
        (or POST /v1/content/bulk-generate for all products without a draft)
2. generate_description.delay(tenant_id, product_id) fires into Celery
3. Worker: load tenant + product from DB
4. Build system prompt: consultant persona + pack copy guidance
5. Build user prompt: title, price, categories, domain_attributes (None-safe)
6. Call LLMGateway.complete(GENERATE, system, user, DescriptionDraft, max_tokens=2048)
7. Parse DescriptionDraft(html="<p>Generated...</p>")
8. upsert_content_draft(session, tenant_id, product_id, "description_html", html)
9. Merchant reviews: GET /v1/content/products/{id}/draft
10. Merchant approves: POST /v1/content/products/{id}/draft/approve
    → writes draft_text to Product.description_html
    → sets draft.status = "approved"
```

### ContentDraft model

```python
class ContentDraft(Base):
    __tablename__ = "content_draft"
    __table_args__ = (
        UniqueConstraint("tenant_id", "product_id", "field",
                         name="uq_content_draft_tenant_product_field"),
    )
    tenant_id, product_id, field   # composite unique — one draft per field per product
    draft_text: str                # the generated HTML
    status: str                    # "pending" → "approved" (or "rejected")
    approved_at: datetime | None   # set when merchant approves
```

**One draft per field per product.** The `field` column allows future expansion
(`"meta_title"`, `"meta_description"`, etc.). The `UniqueConstraint` means re-generating
replaces the previous draft (upsert = DELETE + INSERT).

### None-safe attribute filtering

```python
# Falsy check `if v` would drop spf=0 (a valid value)
attr_lines = "\n".join(f"- {k}: {v}" for k, v in attrs.items() if v is not None)
```

Always use `if v is not None` not `if v` when filtering domain attributes — `spf: 0`,
`fragrance_free: False`, `concentration: 0.5` are all valid values.

---

## Module 20 — FastAPI Middleware Deep Dive

**Middleware** wraps every request before and after the route handler. Helix has four:

### 1. CORS Middleware

```python
app.add_middleware(
    CORSMiddleware,
    allow_origins=s.cors_allowed_origins,   # ["*"] in dev, specific domains in prod
    allow_methods=["GET", "POST", "PATCH"],
    allow_headers=["Authorization", "Content-Type", "X-Helix-Tenant-Key", ...],
    expose_headers=["X-Request-Id"],
)
```

**CORS (Cross-Origin Resource Sharing):** browsers block JavaScript from calling APIs on
different domains by default. The CORS middleware adds response headers that tell browsers
"yes, requests from these origins are allowed." The widget JS on `store.com` calling the
Helix API at `api.helix.com` requires CORS to be configured.

### 2. Request ID Middleware

```python
class RequestIdMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request, call_next):
        request_id = request.headers.get("X-Request-Id") or str(uuid4())
        response = await call_next(request)
        response.headers["X-Request-Id"] = request_id
        return response
```

Every request gets a unique ID. If it came with one (from the client), echo it back.
This enables distributed tracing: the widget sends `X-Request-Id` and the support team
can find the exact request in logs.

### 3. Rate Limit Middleware (sliding window, per-tenant)

Applies to widget endpoints only. Blocks abusive clients. Fails open (if Redis is down,
let requests through — don't break real users because of an infrastructure issue).

### 4. Quota Middleware (monthly limit, per-tenant)

Applies to `/v1/widget/chat` and `/v1/widget/routine`. Enforces the monthly query limit
per tenant. Uses Redis `INCR` (atomic). Returns `429` with `X-Quota-Exceeded: monthly`
header when the limit is exceeded.

**Middleware order matters.** Starlette applies middleware in reverse registration order:
last-added runs first. In Helix: RateLimit → Quota → RequestId → CORS (outer to inner).

---

## Module 21 — Testing Philosophy & Patterns

### The testing philosophy

Helix tests business logic with **unit tests** that mock infrastructure (database, Redis,
Anthropic API). The 17 failing tests require live infrastructure — they're marked as
known infra-dependency failures, not bugs.

**Three categories:**
1. **Pure unit tests:** no I/O. Test a function's logic with mock inputs.
2. **Integration-like tests:** use `TestClient` (FastAPI's test client) with dependency
   overrides to test the full request/response cycle without a live database.
3. **Infrastructure tests (17 failing):** require live Redis/Anthropic API. Skipped in
   CI without those services.

### `asyncio_mode = "auto"` — never write `@pytest.mark.asyncio`

```toml
# pyproject.toml
[tool.pytest.ini_options]
asyncio_mode = "auto"
```

This tells `pytest-asyncio` to treat all `async def test_*` functions as asyncio tests
automatically. Adding `@pytest.mark.asyncio` is redundant and creates confusion.

### FastAPI `TestClient` and dependency overrides

```python
from fastapi.testclient import TestClient
from helix.api.app import create_app
from helix.api.deps import get_tenant
from tests.conftest import make_test_settings

def test_something():
    settings = make_test_settings()
    app = create_app(settings)
    client = TestClient(app)

    # Replace the real auth dependency with a mock
    tenant = MagicMock(spec=Tenant)
    tenant.id = uuid4()
    app.dependency_overrides[get_tenant] = lambda: tenant

    r = client.get("/v1/dashboard")

    app.dependency_overrides.clear()   # ALWAYS clean up
    assert r.status_code == 200
```

`dependency_overrides` is a dict mapping the real dependency function to a replacement.
`.clear()` after the test prevents one test's overrides from leaking into the next.

### Patching at the usage site

```python
# RIGHT — patch where the name is imported and used
with patch("helix.api.routers.content.list_content_drafts", new_callable=AsyncMock, return_value=[]):
    r = client.get("/v1/content/drafts")

# WRONG — patching at the definition site doesn't intercept the usage
with patch("helix.db.crud.content.list_content_drafts", ...):  # too late, already imported
    ...
```

Python's `patch` replaces the name in the module where it's used, not where it's defined.
Always patch at the usage site.

### `AsyncMock` vs `MagicMock`

- `MagicMock()` — for synchronous objects. Accessing any attribute returns another
  `MagicMock`.
- `AsyncMock()` — for async functions/coroutines. `await mock()` works and returns
  `mock.return_value`.
- `MagicMock(spec=Product)` — a mock that only allows attributes that exist on `Product`.
  Raises `AttributeError` for typos — valuable for catching mistakes.

### `make_test_settings()`

```python
# tests/conftest.py
def make_test_settings() -> Settings:
    return Settings(
        database_url="postgresql://user:pass@localhost/test",
        redis_url="redis://localhost:6379",
        anthropic_api_key="test-key",
        ...
    )
```

Tests never read `.env` — they use `make_test_settings()` to create a deterministic
settings object with fake values that don't need real infrastructure.

### Testing PATCH endpoints with `get_db` override

PATCH endpoints call `await db.commit()`. If you don't override `get_db`, the test
reaches SQLAlchemy's real session layer, which rejects a `MagicMock(spec=Product)` with
`UnmappedInstanceError`.

```python
mock_db = AsyncMock()
mock_db.commit = AsyncMock()
app.dependency_overrides[get_db] = lambda: mock_db
```

---

## Module 22 — Docker & Infrastructure

### What Docker does

Docker packages an application and its dependencies into a **container** — a lightweight,
isolated environment that runs the same everywhere: your laptop, a staging server, production.

A **Docker image** is the blueprint. A **container** is a running instance of an image.

### `docker-compose.yml`

Helix's compose file defines the services that run together:

```yaml
services:
  api:          # FastAPI service
  worker:       # Celery worker
  postgres:     # PostgreSQL database
  redis:        # Redis (cache + broker)
```

`docker compose up` starts all four. `docker compose up api postgres redis` starts only
the services you need for development.

### Service dependencies

The API needs the database to be running before it starts. Compose handles this with
`depends_on`.

### The `.env` file

Production secrets (API keys, database passwords) live in a `.env` file that is:
- Listed in `.gitignore` — **never committed to git**
- Mounted into containers at runtime
- Read by Pydantic's `BaseSettings` automatically

---

## Module 23 — The Full API Surface

### Admin endpoints (Provision key auth)
| Method | Path | Description |
|---|---|---|
| `POST` | `/v1/tenants/provision` | Create a new tenant |
| `GET` | `/v1/admin/stats` | Platform-wide totals (all tenants) |
| `GET` | `/v1/admin/tenants` | Paginated tenant list |
| `GET` | `/v1/admin/tenants/{id}` | Single tenant detail |
| `GET` | `/v1/admin/tenants/{id}/usage` | Usage summary for a tenant by month |
| `POST` | `/v1/admin/tenants/{id}/quota/reset` | Reset a tenant's monthly quota |

### Merchant endpoints (Tenant key auth)
| Method | Path | Description |
|---|---|---|
| `GET` | `/v1/tenants/{id}` | Own tenant detail |
| `PATCH` | `/v1/tenants/{id}` | Update pack_id or settings |
| `POST` | `/v1/sync/products` | Ingest canonical products |
| `POST` | `/v1/sync/customers` | Ingest canonical customers |
| `POST` | `/v1/sync/orders` | Ingest canonical orders |
| `PATCH` | `/v1/sync/customers/{platform_id}/profile` | Update customer profile |
| `POST` | `/v1/webhooks/products` | WooCommerce product webhook |
| `POST` | `/v1/webhooks/orders` | WooCommerce order webhook |
| `POST` | `/v1/webhooks/shopify/products` | Shopify product webhook |
| `POST` | `/v1/webhooks/shopify/orders` | Shopify order webhook |
| `GET` | `/v1/search/products` | Semantic vector search |
| `GET` | `/v1/search/browse` | Paginated product browse (no embedding required) |
| `GET` | `/v1/search/suggest` | Product title autocomplete |
| `GET` | `/v1/search/similar/{product_id}` | Similar products by embedding |
| `GET` | `/v1/products/{id}` | Product detail including description_html |
| `PATCH` | `/v1/products/{id}` | Update product fields |
| `GET` | `/v1/customers` | Paginated customer list |
| `GET` | `/v1/customers/{id}` | Customer detail |
| `GET` | `/v1/customers/{id}/conversations` | Customer's conversations |
| `GET` | `/v1/conversations` | Paginated conversation list |
| `GET` | `/v1/conversations/{id}` | Conversation detail with messages |
| `GET` | `/v1/analytics/usage` | LLM usage analytics by model |
| `GET` | `/v1/analytics/quota` | Current quota status |
| `GET` | `/v1/analytics/conversations` | Conversation volume and feedback rates |
| `GET` | `/v1/analytics/top-queries` | Most frequent customer queries |
| `GET` | `/v1/analytics/orders` | Order revenue analytics |
| `GET` | `/v1/analytics/orders/by-status` | Orders grouped by fulfillment status |
| `GET` | `/v1/analytics/products/inventory` | In-stock vs out-of-stock breakdown |
| `GET` | `/v1/analytics/products/top` | Top products referenced by AI |
| `GET` | `/v1/analytics/products/embedding-coverage` | How many products are embedded |
| `GET` | `/v1/analytics/customers/segments` | Customer segments by skin type |
| `GET` | `/v1/jobs` | Background job list |
| `GET` | `/v1/jobs/{id}` | Job status and progress |
| `GET` | `/v1/packs` | List available domain packs |
| `GET` | `/v1/packs/{id}` | Pack detail |
| `POST` | `/v1/jobs/embed/bulk` | Queue embedding for all un-embedded products |
| `POST` | `/v1/content/products/{id}/generate` | Queue AI description generation |
| `GET` | `/v1/content/products/{id}/draft` | Get current draft |
| `POST` | `/v1/content/products/{id}/draft/approve` | Approve draft → writes to product |
| `POST` | `/v1/content/bulk-generate` | Queue description generation for all without draft |
| `GET` | `/v1/content/drafts` | List all content drafts (filterable by status) |
| `GET` | `/v1/dashboard` | Merchant dashboard summary |

### Widget endpoints (JWT Bearer auth)
| Method | Path | Description |
|---|---|---|
| `POST` | `/v1/widget/session` | Issue a short-lived widget JWT |
| `POST` | `/v1/widget/chat` | Widget chat (non-streaming) |
| `POST` | `/v1/widget/chat/stream` | Widget chat (SSE streaming) |
| `POST` | `/v1/widget/routine` | Build a skincare routine |
| `POST` | `/v1/widget/conversations/{message_id}/feedback` | Submit thumbs up/down |

### Public endpoints (no auth)
| Method | Path | Description |
|---|---|---|
| `GET` | `/v1/health` | Health check |
| `GET` | `/v1/widget/embed.js` | Embeddable widget JavaScript |
| `GET` | `/v1/widget/demo.html` | Dev-only widget demo page |

---

## Module 24 — Architecture Decisions Record

These are the five formal ADRs documenting the key architecture choices:

**ADR-0001: Hosted multi-tenant core vs self-contained plugin**
Chose hosted service. Plugins can't host real backend logic, secrets, or background
workers safely. A hosted service serves all verticals from one codebase.

**ADR-0002: PostgreSQL + pgvector single datastore**
Chose one database over PostgreSQL + a dedicated vector DB (Pinecone, Weaviate).
Simpler operations, one backup strategy, pgvector is "good enough" for thousands of
products per tenant.

**ADR-0003: Domain pack as declarative data**
Vertical-specific configuration lives in YAML, not code. New vertical = new pack file.
Core code stays ignorant of any specific vertical.

**ADR-0004: Voyage AI voyage-3-lite for product embeddings**
1024-dimension dense vectors. Good price/quality ratio. Alternative: OpenAI embeddings
(higher cost), sentence-transformers (self-hosted, operational overhead).

**ADR-0005: LLM gateway layered routing (vector → rules → templates → LLM)**
Most queries answered by cheaper layers (templates = zero cost, rules = zero cost) before
reaching Claude. Estimated 70%+ of queries answered by layers 2-3, cutting LLM cost
proportionally.

---

## Glossary

**202 Accepted:** HTTP status meaning "received and queued, not yet done." Used when a
request triggers an async task (embedding, description generation).

**409 Conflict:** HTTP status meaning "the request conflicts with current state." Used
when trying to approve an already-approved draft.

**422 Unprocessable Entity:** HTTP status meaning "I understood the request, but the data
is invalid." FastAPI returns this automatically on Pydantic validation failures.

**Alembic:** Database migration tool for SQLAlchemy. Tracks schema changes as versioned
Python scripts.

**Anthropic:** The AI safety company that makes Claude. Helix uses the Anthropic Python
SDK to call Claude.

**asyncio:** Python's built-in library for writing concurrent code using `async`/`await`.
Allows a single thread to handle thousands of simultaneous I/O-bound operations.

**Celery:** Distributed task queue. Lets you run work asynchronously in background
worker processes.

**Cosine similarity:** A measure of how similar two vectors are, based on the angle
between them. 1.0 = identical direction, 0.0 = perpendicular, -1.0 = opposite.

**ContentDraft:** The intermediate state before an AI-generated description is approved.
Merchants review it before it replaces the product's live description.

**CORS:** Cross-Origin Resource Sharing. A browser security mechanism controlling which
origins can call an API.

**Dependency injection (FastAPI):** `Depends(get_db)` — FastAPI calls `get_db()` and
injects the result into your route handler automatically.

**Embedding:** A fixed-size vector of floats representing the semantic meaning of text.
Generated by a model (Voyage AI in Helix). Similar meanings → similar vectors.

**Fernet:** A symmetric encryption scheme. Uses a 32-byte key to encrypt and decrypt
data. Helix uses it for `credentials_enc`.

**`flush()`:** Send pending SQL to the database within the current transaction, without
committing. Useful to get server-generated IDs before the transaction ends.

**HMAC:** Hash-based Message Authentication Code. A signature computed from a shared
secret + message body. Used to verify webhooks came from the real platform.

**Idempotent:** An operation that can be safely repeated without changing the result
beyond the first application. GET is always idempotent. Helix designs write operations
(upserts) to be idempotent so Celery retries are safe.

**JWT:** JSON Web Token. A signed, self-contained token. The signature (using a secret
key) proves the token wasn't tampered with. Helix uses JWTs for widget sessions.

**LLMParseError:** Raised when the LLM gateway can't parse Claude's response as valid
JSON after a repair attempt. Not retried by Celery — parse failures don't self-heal.

**`lru_cache`:** Python decorator that memoizes a function's result. `get_settings()` is
cached so the Settings object is created only once per process.

**Middleware:** Code that wraps every request/response. Runs before the route handler
(pre-processing) and after (post-processing). Helix middleware: CORS, request ID, rate
limit, quota.

**Minor units:** The smallest denomination of a currency. Cents for USD, pence for GBP,
jeon for KRW. Always use integers for money; divide by 100 only when displaying.

**Multi-tenancy:** One application serving multiple isolated customers (tenants). Data
isolation enforced by scoping every query with `tenant_id`.

**ORM:** Object-Relational Mapper. Translates between Python objects and database rows.
Helix uses SQLAlchemy 2.0.

**Pack:** A YAML + directory defining everything vertical-specific — product attributes,
customer profile schema, routine steps, compatibility rules, prompt templates.

**pgvector:** A PostgreSQL extension adding a `vector` column type and cosine/L2
distance operators for semantic similarity search.

**Pydantic:** Python data validation library. Defines typed models. FastAPI uses it for
request/response parsing and automatic validation.

**RAG (Retrieval-Augmented Generation):** Retrieve relevant documents first, then give
them to the LLM as context so it answers from your data, not its training data.

**Redis:** In-memory key-value store. Helix uses it for: LLM response cache, rate limit
counters, monthly quota counters, and as Celery's task broker.

**`response_model`:** A FastAPI route parameter that validates the return value against a
Pydantic model before serializing to JSON. Documents the schema in Swagger.

**`scalar_one_or_none()`:** SQLAlchemy result method — return the single scalar value or
`None` if no rows. Raises if multiple rows match.

**SecretStr:** Pydantic type for sensitive values. `.get_secret_value()` accesses the
raw string. Never appears in logs or repr output automatically.

**SSE (Server-Sent Events):** A mechanism for the server to push events to a browser
client over a persistent HTTP connection. Uses `text/event-stream` content type.
Unidirectional (server → client only).

**Tenant:** A single merchant (store) using Helix. One database, many tenants.

**TypeVar:** Python's way of declaring a generic type parameter. In the LLM gateway:
`T = TypeVar("T", bound=BaseModel)` means "some Pydantic model." Used to type the return
value of `complete()` as the same type as `response_schema`.

**Upsert:** Insert if not exists, update if it does. PostgreSQL: `INSERT ... ON CONFLICT
DO UPDATE`. Helix CRUD upsert pattern for products, customers, orders.

**Voyage AI:** The embedding provider Helix uses. `voyage-3-lite` produces 1024-dimension
vectors. Called via the `voyageai` Python SDK.

**Webhook:** An HTTP callback — the remote platform sends a POST request to you when
something happens (product updated, order placed). Opposite of polling.

---

## Build It From Scratch Roadmap

Build in this order — each step is usable before the next exists:

1. **Core skeleton** — FastAPI app, one health endpoint, Docker Compose with Postgres
   and Redis. `GET /v1/health` works.

2. **Data models + migrations** — `Tenant`, `Product`, `Customer`, `Order` models.
   Alembic migration 0001. Can insert rows.

3. **Multi-tenancy + auth** — `GET /v1/tenants/provision` creates tenants. `get_tenant`
   dependency checks the public key. Every query filtered by `tenant_id`.

4. **Sync endpoints** — `POST /v1/sync/products`, `customers`, `orders`. Data flows from
   connectors into Postgres. WooCommerce PHP plugin sends data here.

5. **Embeddings + semantic search** — Voyage AI embedding in a Celery task. pgvector
   column. `GET /v1/search/products` works. First visible AI feature.

6. **LLM gateway + widget chat** — `LLMGateway`, 4-layer routing, `POST /v1/widget/chat`.
   The widget can answer shopper questions.

7. **Domain pack** — extract all K-beauty specifics to YAML. Core doesn't know the
   vertical. Routine builder.

8. **Widget JS + session tokens** — `GET /v1/widget/embed.js`. Shoppers see the chat UI.
   JWT session auth.

9. **Shopify connector** — second connector using the same contract. Core unchanged.

10. **Conversation history** — persist turns, multi-turn context in LLM calls.

11. **Analytics** — usage events, order analytics, customer segments, top queries.

12. **Content generation** — AI product descriptions with draft/approve workflow.

13. **Merchant management** — dashboard summary, product update endpoints, content
    review queue.

**The three hard parts** (where you'll spend disproportionate effort — expect it):
- Keeping the connector boundary clean as platforms differ in annoying ways
- Getting structured, grounded LLM output that never invents product facts
- Keeping the engine free of vertical-specific logic as deadlines tempt you to
  "just hardcode this one thing"

Protect those three boundaries and the rest is ordinary, careful engineering.
