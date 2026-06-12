# LEARNING.md — Build Helix From Scratch, The Mentor's Course

> **Who this is for.** You, later — once the system exists and you want to understand it
> deeply enough to have built it yourself, by hand, without an agent. This is written as
> a mentor would teach it: in the order you'd actually learn it, explaining *why* each
> decision was made, what the alternatives were, where the traps are, and giving you
> something to build at each step. Read it alongside the real code; every module points
> to where the concept lives.
>
> **How to use it.** Don't binge it. Take one module, study the concepts, then do the
> "Build it yourself" exercise in a throwaway repo before you read how Helix does it.
> You learn by building the smaller thing first, then recognizing it in the big thing.

---

## Module 0 — The mental model (start here, it's the most important page)

Before any code, internalize three ideas. Everything else is detail.

**1. The intelligence is a service, not a plugin.** A plugin sits inside someone's
store. A service runs on your own servers and stores talk to it. We chose a service
because (a) Shopify forbids running real backend logic in-store, (b) AI features need
secrets, a database, and background jobs a plugin can't host well, and (c) one service
can serve many stores and many verticals while a plugin is trapped in one. If you
remember nothing else, remember: *the store is a thin client; the brain is hosted.*

**2. There are exactly three things that vary, and we isolate each one.**
- The *platform* varies (Woo vs Shopify) → isolated behind the **Connector Contract**.
- The *vertical* varies (K-beauty vs auto parts) → isolated in the **domain pack**.
- The *model* varies (which Claude tier, prompt wording) → isolated behind the **LLM
  gateway**.
Everything else is shared core. Good architecture here is mostly "draw these three
boundaries and never let them leak."

**3. Multi-tenancy is a security property, not a feature.** Many stores share one
database. The unforgivable bug is one store seeing another's data. So tenant isolation
is enforced at the lowest layer (the data access), not politely requested at the top.

Study: the words *multi-tenant SaaS*, *separation of concerns*, *the dependency rule*
(business logic shouldn't depend on frameworks). Read about hexagonal / "ports and
adapters" architecture — the connector and pack systems are exactly that pattern.

---

## Module 1 — HTTP services and APIs (the spine)

**What you're learning:** how a web service receives a request, does work, and replies;
why REST and JSON; what a webhook is and why it's "the platform calling you" instead of
you polling.

**Why we built it this way:** FastAPI gives typed request/response models (Pydantic),
automatic validation, and generated docs with very little ceremony. We keep routers
*thin* — they translate HTTP to a function call in `domain/` and back — so the real
logic is testable without HTTP.

**Concepts to master:** HTTP verbs and status codes; request/response lifecycle; JSON;
REST resource design; idempotency; webhooks and why you verify their signatures;
synchronous vs asynchronous work.

**In the code:** `services/core/helix/api/`.

**Build it yourself:** a FastAPI service with one `POST /echo` that validates a Pydantic
body, and one `POST /webhook` that verifies an HMAC signature before trusting the body.
You now understand 80% of how connectors talk to the core.

---

## Module 2 — Data modeling and the database

**What you're learning:** how to design tables, how an ORM maps objects to rows, how
migrations evolve a schema safely, and why money is stored as integer minor units.

**Why this way:** PostgreSQL is boring in the best sense — reliable, well-understood,
and with `pgvector` it also stores our embeddings, so we avoid running a second database
until we've proven we need one. SQLAlchemy 2.0 + Alembic give typed models and
versioned migrations.

**Concepts to master:** relational modeling, primary/foreign keys, indexes, transactions,
normalization vs. a JSON column (we use a JSON `domain_attributes` column precisely
*because* its shape varies per vertical — learn when JSON-in-SQL is right and when it's
lazy). Learn what an embedding/vector is and what a similarity search does.

**In the code:** `services/core/helix/db/`, and the canonical models in
`helix/connectors/`.

**Build it yourself:** model `tenant` and `product` with a foreign key, write an Alembic
migration, insert rows, and write one query that is *always* filtered by `tenant_id`.
Feel why that filter must never be optional.

---

## Module 3 — Multi-tenancy and auth (do not skip the security thinking)

**What you're learning:** how one system serves many isolated customers; how stores
authenticate; how the public widget gets a safe, limited token.

**Why this way:** every query is scoped by `tenant_id` at the data layer so there is no
code path that can leak across tenants. Store credentials are encrypted at rest. The
storefront widget never holds a secret — it gets a short-lived, single-tenant session
token.

**Concepts to master:** authentication vs authorization; API keys vs JWTs; token scope
and expiry; encryption at rest; the principle of least privilege; why you treat anything
from a browser or webhook as hostile and validate it.

**In the code:** `helix/api/` auth dependencies, credential storage, session-token
issuance.

**Build it yourself:** issue a JWT scoped to one tenant with a 15-minute expiry, then
write middleware that rejects any request whose token tenant doesn't match the data it's
reaching for.

---

## Module 4 — The Connector Contract (the platform-agnostic boundary)

**What you're learning:** how to design a stable interface that hides the differences
between WooCommerce and Shopify so the core never knows which it's talking to.

**Why this way:** if the core ever contains `if platform == "shopify"`, the abstraction
has failed and adding a third platform becomes a rewrite. Instead, each connector
translates its platform's data into *canonical models*, and the core only ever sees
those. This is the single most valuable boundary in the project.

**Concepts to master:** interface/contract design; the adapter pattern; data
normalization (mapping two different shapes to one canonical shape); WordPress plugin
basics (hooks, REST endpoints) for Woo; OAuth and the Admin GraphQL API for Shopify;
full vs incremental sync; webhook-driven deltas.

**In the code:** `helix/connectors/` (canonical models + sync endpoints) and the two
thin clients in `connectors/woocommerce/` and `connectors/shopify/`.

**Build it yourself:** write two tiny scripts that each fetch products from two different
fake "platforms" with different JSON shapes and emit the *same* `CanonicalProduct`. When
the core code downstream can't tell which source it came from, you've understood the
whole idea.

---

## Module 5 — Embeddings and semantic search (the first "AI" piece)

**What you're learning:** how to turn text into vectors, store them, and find the most
similar items — the engine behind "search by meaning, not keywords."

**Why this way:** keyword search fails the moment a shopper types intent ("something for
a damaged moisture barrier") instead of a product name. Embeddings put meaning in
geometry; nearest-neighbour search finds the right products. We keep vectors in
`pgvector` next to the relational data to stay simple.

**Concepts to master:** what an embedding is; cosine similarity / nearest neighbour;
chunking and what you choose to embed (title + key attributes, not raw HTML); when to
re-embed (on product update); the cost/latency of embedding at scale (hence the async
worker).

**In the code:** the embedding Celery task and the search query in `helix/domain/`.

**Build it yourself:** embed 50 product titles, store the vectors, and retrieve the
nearest 5 to a free-text query. You now have the heart of discovery.

---

## Module 6 — Background work (Celery, Redis, and why not everything is "now")

**What you're learning:** how to do slow work (syncing a 5,000-product catalog,
generating embeddings, bulk-writing descriptions) without making a user wait, and how to
schedule recurring jobs (replenishment reminders).

**Why this way:** request handlers must stay fast. Anything slow or retryable goes onto a
queue and a worker picks it up. Redis is the broker; Celery runs the tasks. This is also
how you survive third-party rate limits and transient failures — the task retries.

**Concepts to master:** message queues; producers/consumers; idempotent tasks (a task may
run twice — design for it); retries and backoff; scheduled/periodic jobs; the difference
between this and `n8n` (which we reserve for *external* marketing flows, never core
paths).

**In the code:** `helix/workers/`.

**Build it yourself:** enqueue a task that "syncs" 1,000 fake products in batches and
make it safe to run twice without duplicating data.

---

## Module 7 — The LLM gateway (talking to Claude like an engineer, not a hobbyist)

**What you're learning:** how to call an LLM reliably in production — picking the right
model for the job, getting *structured* output you can trust, handling failure, and
metering cost.

**Why this way:** if every feature calls the Anthropic SDK directly, you get
inconsistent prompts, no cost visibility, and brittle parsing. One gateway centralizes
model-tier selection (cheap model for classification, stronger for reasoning), prompt
assembly, JSON parsing with validation and a bounded repair retry, retries/timeouts, and
per-tenant usage metering. It's the difference between a demo and a business.

**Concepts to master:** prompt engineering (clear instructions, examples, asking for
specific structure); system vs user messages; structured/JSON output and validating it;
temperature and determinism; token cost and why model tiering matters; retries and
graceful degradation; **grounding / RAG** — the rule that factual answers must come from
supplied context, never the model's imagination.

**In the code:** `helix/llm/`.

**Build it yourself:** write a function that asks Claude to classify a product into one
of five categories and *return only JSON*, then validate that JSON against a schema and
retry once if it's malformed. This single pattern underlies every AI feature.

---

## Module 8 — Retrieval-augmented generation (RAG) and grounded answers

**What you're learning:** how to combine Module 5 (find the relevant products/reviews)
with Module 7 (have Claude reason over them) so the AI answers from *your* data, not from
guesses — the technique behind the consultant, Q&A, and support agent.

**Why this way:** an ungrounded model will confidently invent product claims, which is
catastrophic in commerce (and a legal risk in beauty). RAG forces every answer to cite
retrieved context, and the system says "I don't know, here's support" when context is
missing.

**Concepts to master:** the retrieve→assemble-context→generate loop; context-window
budgeting; preventing hallucination by instruction and by withholding ungrounded paths;
evaluating answer quality.

**In the code:** the consultant/Q&A services in `helix/domain/` wiring search + gateway.

**Build it yourself:** answer "which of my products suits oily skin?" by first retrieving
candidates, then passing only those to Claude with an instruction to choose from them
and admit when none fit.

---

## Module 9 — The domain pack (making the engine vertical-agnostic)

**What you're learning:** how to push everything K-beauty-specific out of the code and
into data, so a new vertical is a new pack — not a new codebase.

**Why this way:** this is how the same engine serves a skincare store and an auto-parts
store. A pack declares the customer profile schema, product attributes, taxonomy,
compatibility rules, and prompt fragments. The core loads and validates a pack at
runtime and stays ignorant of the vertical. The discipline: if you ever type a skincare
word inside `services/core`, you've leaked the boundary.

**Concepts to master:** configuration-over-code; schema validation (Pydantic/JSON
Schema); rules-as-data vs rules-as-code (and where the line is — the compatibility rules
are mostly data, with a thin code engine); designing for the *second* use case to keep
the first honest.

**In the code:** `helix/packs/` (loader + base schema) and `packs/kbeauty/`.

**Build it yourself:** define a tiny pack as YAML with a profile schema and three
compatibility rules, write a loader that validates it, and a function that applies the
rules — then write a *second* pack for a different domain and run the same engine
unchanged. The moment that works, you've understood the entire product.

---

## Module 10 — Compatibility / fitment reasoning (the conversion superpower)

**What you're learning:** how to model "these products work together / this one suits
this person" — the logic behind the routine builder and the upsell that isn't random.

**Why this way:** generic "you may also like" is weak. Reasoning over real rules
(ingredient conflicts, layering order; or for auto parts, fitment to a vehicle) is
defensible and converts. It's the same primitive in both verticals — which is exactly
why it lives in the engine with the rules supplied by the pack.

**Concepts to master:** rule engines; constraint satisfaction at a small scale;
explaining a recommendation (why these go together) because shoppers trust what they
understand; combining hard rules with LLM reasoning for the fuzzy parts.

**In the code:** the compatibility engine in `helix/domain/`, fed by the pack's rules.

**Build it yourself:** given a list of products with tags and a few "X conflicts with Y"
and "X must come before Y" rules, assemble a valid ordered routine and explain it in a
sentence.

---

## Module 11 — The embeddable widget (shipping UI into a stranger's website)

**What you're learning:** how to put your interface onto any store's theme without your
CSS breaking theirs or vice versa, and how it talks safely back to the core.

**Why this way:** one widget bundle, loaded by both connectors, keeps the UI shared. We
build it as a custom element with a shadow DOM so styles are isolated, kept small (Preact)
so it loads fast, and it authenticates with the short-lived session token from Module 3 —
never a secret.

**Concepts to master:** Web Components / custom elements; the shadow DOM and style
isolation; bundling for size; talking to an API from the browser with a scoped token;
CORS; not blocking the host page.

**In the code:** `services/widget/`.

**Build it yourself:** a `<hello-widget>` custom element with a shadow DOM that fetches
from a tiny API and renders the result, dropped into a plain HTML page without disturbing
its styles.

---

## Module 12 — The merchant dashboard, billing, and observability

**What you're learning:** the "business" layer — where merchants configure the suite, how
you charge them, and how you know the system is healthy and what it costs you per tenant.

**Why this way:** metered LLM usage means you must track cost per tenant from day one
(built in the gateway, Module 7). PayFast serves SA/direct tenants; Shopify's Billing API
serves Shopify-native ones. Observability (structured logs, error tracking, the usage
table) is what turns "it works on my machine" into "it works for paying customers."

**Concepts to master:** SaaS billing and metering; webhooks for payment events; admin UI
patterns; structured logging; error tracking; basic dashboards/alerts; PoPIA/privacy
basics for SA.

**In the code:** `services/dashboard/`, billing modules, the `usage_event` table.

**Build it yourself:** record a usage event per API call with a cost estimate and show a
running monthly total per tenant. That number is your margin.

---

## The from-scratch roadmap (if you removed the agent entirely)

Build in this order — each step is usable before the next exists:

1. Core service skeleton + Postgres + one canonical `Product` model (Modules 1–2).
2. Tenancy + auth so data is isolated (Module 3).
3. The WooCommerce connector doing catalog sync into canonical products (Module 4).
4. Embeddings + semantic search — your first visibly "smart" feature (Modules 5–6).
5. The LLM gateway, then the consultant via RAG (Modules 7–8).
6. Extract everything K-beauty into a pack; prove it by writing a second pack (Module 9).
7. Compatibility engine → routine builder + non-random upsell (Module 10).
8. The widget, so real shoppers can use any of it (Module 11).
9. Shopify connector to the *same contract* — note how little core changes (Module 4 again).
10. Dashboard, billing, observability — turn it into a business (Module 12).

**The three hard parts** (where you'll spend disproportionate effort, so expect it):
keeping the connector boundary clean as platforms differ in annoying ways; getting
structured, grounded LLM output that never invents product facts; and keeping the engine
free of vertical-specific logic as deadlines tempt you to "just hardcode this one thing."
Protect those three boundaries and the rest is ordinary, careful engineering.

> Keep this file honest: as the real system changes, update the module that teaches the
> changed concept, so the course always describes the system that actually exists.
