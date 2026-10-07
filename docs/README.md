# Smarthost documentation

| Document | Purpose |
|---|---|
| `20260908-1644-smarthost-llm-spec.yaml` | **Authoritative** specification (version 2.10, 2026-10-07) |
| `20260908-1644-smarthost-human-specification.md` | Human-readable companion to the spec (version 2.10) |
| `PROJECT.md` | Directory structure, purpose of every file, workflow diagrams |
| `development-environment.md` | Rootless Podman environment: usage, pod topology, ports, volumes, mail safety, established facts, the Phase 1 verification suite (including the persistent pod lifecycle), the Phase 2 application and test suite, the Phase 3 validator test suite and the Phase 4 delivery suites |
| `production/README.md` | Production topology (Phase 8): network matrix, the socket-activated ingress (client addresses), the low-port host setting, launch decisions, delivery states, where state lives |
| `production/runbook.md` | Production runbook: installation, DNS identity, activation, seed tests, warm-up, emergencies, backups, upgrades |
| `production/onboarding.md` | Client onboarding and SaaS operations (Phase 9): checklist, lifecycle, limits, API keys, reputation alerts, usage and billing statements, the public-onboarding decision |
| `api/integration-guide.md` | Client integration guide (public at `/docs/api`): authentication, limits, jobs, webhooks, the meaning of statuses |
| `architecture/overview.md` | One-page map of contracts, services, flows and state machines (summary only) |
| `architecture/conventions.md` | Cross-language development conventions |
| `architecture/open-decisions.md` | Decision log only (not authority) |
| `architecture/postfix-integration.md` | Postfix/OpenDKIM/Go channels, cursors, reconciliation, verification tasks |
| `api/openapi.v1.yaml` | `/v1` OpenAPI 3.1 contract |
| `schema/schema.md` | ERD, tenant ownership, indexes, table access matrix |
| `schema/reference-schema.sql` | Reference PostgreSQL 16 DDL (normative; not a migration) |
| `contracts/status-vocabulary.yaml` | Canonical status/event/classification vocabulary |
| `contracts/environment.md` | Environment-variable contract |
