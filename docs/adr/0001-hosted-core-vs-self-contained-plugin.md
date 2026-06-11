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
