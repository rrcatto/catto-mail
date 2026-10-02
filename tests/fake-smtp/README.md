# tests/fake-smtp/ — deterministic SMTP test service

Reproduces 250, 421, 450, 451 and 550 responses, timeouts, connection refusal, greylisting and
accept-all behaviour, for validator and delivery tests. It never relays mail anywhere.

Empty until Phase 1. Port: `FAKE_SMTP_PORT` (`docs/contracts/environment.md`).
