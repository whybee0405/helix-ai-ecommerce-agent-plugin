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

Additionally: Anthropic `cache_control` on system prompts reduces input token costs by ~80% on repeated calls. Redis caches deterministic responses. Batch API used for non-real-time jobs.

## Alternatives Considered
- **Always call Sonnet:** Simpler code, ~$315/mo per store.
- **Always call Haiku:** Cheaper but inadequate quality for generation tasks.

## Consequences
Target: ~$31–35/month per store at 1,000 queries/day — an 89% reduction. Per-tenant `usage_event` rows provide the data to tune layer thresholds over time.
