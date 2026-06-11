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
