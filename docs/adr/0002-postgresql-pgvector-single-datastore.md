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
