# validator/ — Python validation worker

asyncio background worker. Claims validation work from PostgreSQL and runs the stages: syntax,
typo suggestion, DNS/MX/Null-MX, disposable, role, SMTP RCPT probe (never `DATA`), and
evidence-based classification. It has no public API and runs no DDL.

Empty until Phase 1 (container) and Phase 3 (implementation).
Contracts: `docs/contracts/status-vocabulary.yaml`, `docs/schema/schema.md` §6 (table access),
`docs/contracts/environment.md` (`VALIDATOR_*`).
