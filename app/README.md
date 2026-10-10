# app/ — Symfony application

Symfony 8.1 on PHP 8.5, running as **PHP-FPM** behind nginx (FastCGI inside the `smarthost` pod;
never host-published). There is no separate PHP HTTP server.

**Phase 2 (implemented):**
- the `/v1` API of `docs/api/openapi.v1.yaml`: validation-job creation and reads, the staged send-job
  lifecycle (create → recipient batches → submit), message and event reads, and
  `POST /v1/webhooks/test` (since Phase 7: records a `webhook.test` event for the one endpoint
  named by the required `webhook_endpoint_id`);
- API-key authentication (Bearer, stored only as SHA-256), tenant isolation, idempotency and rate
  limiting; RFC 9457 problem responses;
- Doctrine entities and the migrations, which are the only authority for schema objects;
- sending-domain registration and DNS TXT verification, DKIM selector/status records;
- the dashboard user foundation (users and client memberships);
- the webhook endpoint/secret model and the transactional outbox (HTTP delivery since Phase 7);
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
*Access › Users* and *Access › Roles & permissions*. There are no passwords. Migration
`Version20261006000200`.

**Phase 7 (Smarthost side, v0.1.7, specification 2.8):** the **webhook worker**, `bin/console
smarthost:webhook:work` (container `smarthost-webhook-worker`, same image, database role
`smarthost_webhook` through its own Doctrine connection). It is the only component that sends
webhooks: it fans out the `webhook_events` outbox, claims deliveries with leases and fencing,
signs and sends them concurrently with SSRF protection, and retries or fails them by the
documented policy (`App\Webhook\WebhookDispatcher`). Client admins manage endpoints under
*Settings › Webhooks* in their workspace, and operators see deliveries and workers under
*Mail flow › Webhooks*.
Migration `Version20261007000100`.

**Phase 8 (repository-side production readiness, v0.1.8, specification 2.9):**
- `App\Config\SafetyGuard` refuses, with `SMARTHOST_ENV=production`: the debug kernel
  (`APP_ENV=dev`), a non-https base URL, or a webhook private-host allowlist. `smarthostctl prod
  check` requires `APP_ENV=prod`.
- `smarthost:ops:status [--json]` is the production health summary (the operator overview's
  signals).
- `smarthost:ops:record` audits installation-wide delivery controls (live activation, emergency
  pause). It needs the new ADMIN-only permission `SYSTEM.DELIVERY.CONTROL`.
- The operator overview shows *Delivery state and Postfix queue*, from `delivery_heartbeats`
  (migration `Version20261008000100`, written by the Go daemon).
- The webhook worker has a distinct connect timeout (`APP_WEBHOOK_CONNECT_TIMEOUT_SECONDS`) and a
  16 KiB response-header bound.

**Phase 9 (repository-side public SaaS hardening, v0.1.8, specification 2.10):**
- `App\Client\ClientLifecycle` is the only way to create a client or change its status. Each
  transition needs its permission (`PLATFORM.CLIENT.APPROVE` or `PLATFORM.CLIENT.RESTRICT`) and
  a reason, and is audited. Public onboarding is gated by `APP_PUBLIC_ONBOARDING_ENABLED`
  (false), and no route uses it. Approval needs the policy version in force
  (`APP_ACCEPTABLE_USE_POLICY_VERSION`) when the client requires it.
- Per-client limits (`ClientLimitPolicy`) under installation ceilings. `QuotaEnforcer` admits
  volume quotas in the creating transaction: `429 quota-exceeded` with `Retry-After` and a
  `quota` member.
- API keys have names and optional expiry; client admins manage their own under *Settings › API
  keys* in their workspace.
- `App\Usage`: period summaries, reconciliation, provider-neutral billing statements and
  export.
- `App\Reputation`: metrics and alerts, never automatic action. `smarthost:reputation
  evaluate` runs from the production timer every 15 minutes; in development, run it by hand or
  from *Clients › Abuse & reputation*.
- Public API documentation at `/docs/api`.
- Migration `Version20261009000100`. Operator procedures: `docs/production/onboarding.md`.

**Phase 10 (operator self-service, v0.1.9, specification 2.11):**
- `App\System`: the setup wizard (System › System setup), diagnostics with history
  (System › Diagnostics), Mail flow › Delivery with the delivery mode, the emergency stop
  (`delivery_controls`), and the host-agent request queue (`system_requests`; the web
  application never runs host commands).
- `App\AddressBatch`: administrator address batches (up to 10,000 addresses), separate state
  dimensions, staged sends, and the public re-permission page `/p/{token}` with the webhook
  event `repermission.responded`.
- `App\Help`: Help (the round question-mark button).
- Console: `smarthost:system:agent`, `smarthost:admin:login-link`,
  `smarthost:delivery:emergency-stop`. Migration `Version20261010000100`.

**Dashboard redesign (v0.2.2):**
- One shell for the operator console and the client workspaces (`templates/dashboard/layout.html.twig`):
  round area buttons, the area's pages as pills, and at the top right the search, the delivery
  mode, *Stop sending*, the attention bell and the account menu. `App\Dashboard\Navigation`
  builds the areas, pills and badges from the permission keys.
- Both overviews have a period choice (`OverviewPeriod`); `OperatorOverview` and `TrendCharts`
  assemble their figures. Charts are Chart.js (`assets/controllers/chart_controller.js`, loaded
  only on pages with a chart). The Content-Security-Policy allows no inline styles: data-driven
  widths are SVG attributes.
- `App\Dashboard\Search`: the global search, tenant-scoped in a client workspace.
- `App\Util\InstallationTime`: the installation time zone (`APP_TIMEZONE`) for dashboard times,
  overview buckets, date filters, API key expiry, quota days and months, usage and billing.
- Migration `Version20261011000100` (the submission-rate index).

**Later phases:** retention commands; a public registration workflow (owner decision).

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
