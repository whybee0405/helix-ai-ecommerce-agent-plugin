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
Adding a new vertical is a new directory, not a code change. Pack schemas are validated at startup, so malformed packs fail loudly before any request is served.
