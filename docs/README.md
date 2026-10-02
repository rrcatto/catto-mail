# Smarthost documentation

| Document | Purpose |
|---|---|
| `20260908-1644-smarthost-llm-spec.yaml` | **Authoritative** specification (version 2.1, 2026-10-02) |
| `20260908-1644-smarthost-human-specification.md` | Human-readable companion to the spec (version 2.1) |
| `PROJECT.md` | Directory structure, purpose of every file, workflow diagrams |
| `development-environment.md` | Phase 1 rootless Podman environment: usage, topology, observed facts, pending items |
| `architecture/overview.md` | One-page map of contracts, services, flows and state machines (summary only) |
| `architecture/conventions.md` | Cross-language development conventions |
| `architecture/open-decisions.md` | Decision log only (not authority) |
| `architecture/postfix-integration.md` | Postfix/OpenDKIM/Go channels, cursors, reconciliation, verification tasks |
| `api/openapi.v1.yaml` | `/v1` OpenAPI 3.1 contract |
| `schema/schema.md` | ERD, tenant ownership, indexes, table access matrix |
| `schema/reference-schema.sql` | Reference PostgreSQL 16 DDL (normative; not a migration) |
| `contracts/status-vocabulary.yaml` | Canonical status/event/classification vocabulary |
| `contracts/environment.md` | Environment-variable contract |
