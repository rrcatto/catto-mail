# app/ — Symfony application

Symfony 8.1 on PHP 8.5, running as **PHP-FPM** behind nginx (FastCGI inside the `smarthost` pod;
never host-published). There is no separate PHP HTTP server.

**Phase 2 (implemented):**
- the `/v1` API of `docs/api/openapi.v1.yaml`: validation-job creation and reads, the staged send-job
  lifecycle (create → recipient batches → submit), message and event reads, and
  `POST /v1/webhooks/test` (501 until Phase 7);
- API-key authentication (Bearer, stored only as SHA-256), tenant isolation, idempotency and rate
  limiting; RFC 9457 problem responses;
- Doctrine entities and the migrations, which are the only authority for schema objects;
- sending-domain registration and DNS TXT verification, DKIM selector/status records;
- the dashboard user foundation (users and client memberships);
- the webhook endpoint/secret model and the transactional outbox (no HTTP delivery yet);
- the audit log and the administrative console commands (`smarthost:*`).

**Phase 3 (implemented):** work-creating operations answer 403 for `pending_approval` and
`suspended` clients (D-31, `App\Api\WorkPermission`); validation-job creation wakes the Python
validator with `pg_notify`; the D-32 normaliser is tested against the shared vectors; `ext-intl` is
a declared requirement checked by `composer check-platform-reqs`. Validation usage is metered by
the validator (D-33), not by Symfony.

**Phase 5 (implemented, v0.1.5, D-30):** the trusted-client recipient global opt-out API
(`/v1/global-suppressions`, `App\Suppression\GlobalSuppressionService`; 403 without the
operator-granted `can_submit_global_suppressions`), operator suppression administration
(`App\Suppression\SuppressionAdministration`) and the unmatched-DSN workflow
(`App\Dsn\UnmatchedDsnAdministration`: match requests are applied by the Go daemon, never here), as
audited `smarthost:client:global-suppressions`, `smarthost:suppression:*` and `smarthost:dsn:*`
console commands that require `--operator=<login email>`. Migration `Version20261004000100`.

**Phase 6 (implemented, v0.1.6, spec 2.6):**
- public tracking endpoints `GET /t/o/{token}.gif` and `GET /t/c/{token}/{n}` (`App\Tracking\TrackingRecorder`,
  `App\Controller\TrackingController`): eligibility checks, the same pixel or 404 for unknown tokens,
  the bounded recording rule, redirects only to the stored `message_links` target, no cookies, no
  token in any log;
- the client dashboard under `/dashboard/c/{client}` and the operator dashboard under
  `/dashboard/operator` (Twig, AssetMapper, Symfony UX StimulusBundle; no SPA, no CDN): read models
  in `App\Dashboard\` with keyset pagination and an explicit client condition in every query;
  changes are POST + CSRF through the existing audited services (`AccountAdministration`,
  `SuppressionAdministration`, `UnmatchedDsnAdministration`, `GlobalSuppressionService`,
  `SendingDomainService`); CSP with a per-request nonce;
- migration `Version20261006000100` (`message_events_engagement_idx`).

**Passwordless sign-in, roles and ACL (specification 2.7):** `/dashboard/login` emails a single-use
link (`App\Security\LoginLinkService`, Symfony Mailer through Postfix submission, Mailpit in
development; `App\Security\LoginLinkAuthenticator` redeems it). `APP_ADMIN_EMAIL` always receives
ADMIN. Operator pages require permission keys (`App\Access\PermissionCatalog`) granted by roles
(`App\Access\AccessControl`, `PermissionVoter`); users, roles and memberships are managed under
*Operator › Users* and *Roles & permissions*. There are no passwords. Migration
`Version20261006000200`.

**Later phases:** retention commands, and the **webhook worker**, a long-running console process from this image that
consumes the `webhook_events` outbox and is the only component that sends webhooks. Until Phase 7
the worker unit runs the placeholder in `phase1-probe/`.

Configuration comes only from the variables in `docs/contracts/environment.md` (there is no
`.env` file). Run console commands in the development pod with
`infra/bin/smarthostctl console <command>` and the test suite with `infra/bin/smarthostctl test phase2`.
See `docs/PROJECT.md` for every file.

Must not:
- probe mailboxes;
- send tracked or bulk mail (its only mail is the dashboard sign-in link, submitted to Postfix);
- render templates or merge data;
- hold or generate DKIM keys;
- call Python or Go over RPC.
