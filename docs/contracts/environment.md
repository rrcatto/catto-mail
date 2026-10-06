# Smarthost Environment-Variable Contract

**Status:** normative contract for specification 2.8 · **Template:** [`infra/.env.example`](../../infra/.env.example)

This is the single list of configuration variables that Smarthost services may read. A service
must not read a variable that is not listed here. Adding a variable means updating this table and
`infra/.env.example` together. `scripts/check-contracts.py` checks that the two match, that no
secret has a value in the template, and that safety switches default to safe values.

## Rules

1. **No secrets in the repository.** Every variable marked *secret* is empty in
   `infra/.env.example`. Real values live in a non-committed development `.env` or in Podman
   secrets.
2. **`_FILE` indirection.** Any secret `X` may instead be supplied as `X_FILE=/run/secrets/...`.
   Every service must support this. Setting both `X` and `X_FILE` is a startup error.
3. **Least privilege.** The *Consumers* column is normative. Phase 1 gives each container only the
   variables it consumes, as per-service environment files or secrets.
4. **Fail closed.** A missing required variable is a startup error. Safety switches default to the
   safe value: live delivery off, live probing off, unverified sending domains rejected.
   `SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS=true` together with
   `SMARTHOST_ENV=production` is a startup error.
5. **Empty retention means "no automatic deletion".** The `APP_RETENTION_*` periods for
   long-term categories are empty until the operator's compliance specification sets a production
   policy (spec `compliance.retention`). Only transient staged content has a default.
6. **Naming.**
   * `SMARTHOST_` is for variables shared across services.
   * A service prefix is used for variables owned by one service: `APP_`, `VALIDATOR_`,
     `DELIVERY_`, `POSTFIX_`, `OPENDKIM_`, `POSTGRES_`, `MAILPIT_`, `FAKE_SMTP_`, `PROXY_`.
   * Framework-standard names (`APP_ENV`, `APP_SECRET`, `MAILER_DSN`, `TRUSTED_PROXIES`) keep their
     standard form.
7. **Allowed dev/prod differences** (spec `environment_parity.allowed_differences`): secrets,
   domain names, certificates, DKIM keys, live-delivery enablement, Mailpit versus live relay,
   and resource limits.

**Consumers:**

| Consumer | What it is |
|---|---|
| `app` | Symfony PHP-FPM web process and scheduled commands |
| `webhook-worker` | Symfony webhook worker (same image as `app`) |
| `validator` | Python validation worker |
| `delivery` | Go delivery daemon |
| `postfix` | Postfix |
| `opendkim` | OpenDKIM milter |
| `postgres` | PostgreSQL |
| `bootstrap` | `infra/` database role bootstrap and grants |
| `mailpit` | Mailpit (development only) |
| `fake-smtp` | Deterministic SMTP test service |
| `proxy` | nginx reverse proxy |

*Phase* is the first phase that needs the variable.

## Shared

| Variable | Consumers | Secret | Phase | Example | Meaning |
|---|---|---|---|---|---|
| `SMARTHOST_ENV` | app, webhook-worker, validator, delivery, postfix, opendkim | no | 1 | `development` | One of `development`, `test` or `production`. Postfix uses it in the live-mode guard, and OpenDKIM uses it to refuse generating disposable development keys outside `development`/`test`. |
| `SMARTHOST_PUBLIC_BASE_URL` | app, delivery | no | 2 | `https://localhost:8443` | Public HTTPS origin for the API, tracking URLs and emailed dashboard sign-in links (which are built from it, never from a request's Host header). No trailing slash. In development it is the published nginx port, so links in Mailpit open in the browser. |
| `SMARTHOST_LOG_LEVEL` | app, webhook-worker, validator, delivery | no | 1 | `info` | `debug`, `info`, `warning` or `error`. |
| `SMARTHOST_LOG_FORMAT` | app, webhook-worker, validator, delivery | no | 1 | `json` | `json` (default), or `text` for local debugging only. |
| `SMARTHOST_LIVE_DELIVERY_ENABLED` | postfix, delivery | no | 1 | `false` | Capture/live switch. When it is `false` (capture mode), Postfix relays every outbound message to `POSTFIX_RELAYHOST` (Mailpit) and refuses to start without one. `true` (live mode) is accepted **only** with `SMARTHOST_ENV=production` and an empty `POSTFIX_RELAYHOST`; any other combination is a startup error. In development, the internal Podman network additionally has no Internet route. |
| `SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS` | app, delivery | no | 2 | `false` | Controlled local/test mode that lets unverified or DKIM-inactive sending domains be used. It is never permitted in production. |
| `SMARTHOST_BOUNCE_DOMAIN` | delivery, postfix | no | 4 | `bounce.smarthost.localhost` | Dedicated domain for VERP return paths, Message-ID domains and inbound DSNs. |
| `SMARTHOST_VERP_LOCAL_PART` | delivery, postfix | no | 4 | `bounce` | Local-part base of the return path (`bounce+TOKEN@domain`). |
| `SMARTHOST_VERP_DELIMITER` | delivery, postfix | no | 4 | `+` | Must equal the Postfix `recipient_delimiter`. |
| `SMARTHOST_SUBMISSION_USERNAME` | delivery, postfix | no | 4 | `smarthost-delivery` | SASL username Go uses for authenticated submission to Postfix. |
| `SMARTHOST_SUBMISSION_PASSWORD` | delivery, postfix | **yes** | 4 | | SASL password for that account. |
| `SMARTHOST_POSTFIX_OBSERVABILITY_DIR` | postfix, delivery | no | 1 | `/var/lib/smarthost/postfix-observability` | Mount point of the shared observability volume (`log/`, `queue/`). Postfix mounts it read-write; Go mounts it read-only. It must be under `/var`, because Postfix 3.10 `maillog_file_prefixes` only allows `/var` and `/dev/stdout` (verified in Phase 1). |
| `SMARTHOST_DSN_SPOOL_DIR` | postfix, delivery | no | 1 | `/var/lib/smarthost/dsn-spool` | Mount point of the shared DSN Maildir spool. Both mount it read-write, because Go claims files by rename. |
| `SMARTHOST_SPOOL_GID` | postfix, delivery | no | 1 | `5000` | Numeric group that owns both shared volumes. The delivery container runs with this as its primary group. |
| `SMARTHOST_DELIVERY_UID` | postfix, delivery | no | 1 | `5001` | Numeric UID of the Go delivery process. Postfix `virtual(8)` writes DSN Maildir files as this UID (`virtual_uid_maps`), because it creates them with mode 0600 (verified in Phase 1). Go can therefore read and claim them without any write access to the observability volume. |
| `SMARTHOST_OPENDKIM_MILTER_ADDRESS` | postfix, opendkim | no | 1 | `inet:opendkim:8891` | Milter socket on the private network. OpenDKIM listens on it and Postfix connects to it. |

## Database

| Variable | Consumers | Secret | Phase | Example | Meaning |
|---|---|---|---|---|---|
| `SMARTHOST_DB_HOST` | app, webhook-worker, validator, delivery, bootstrap | no | 1 | `postgres` | Hostname on the internal Podman network. Never a public address. |
| `SMARTHOST_DB_PORT` | app, webhook-worker, validator, delivery, bootstrap | no | 1 | `5432` | |
| `SMARTHOST_DB_NAME` | app, webhook-worker, validator, delivery, bootstrap | no | 1 | `smarthost` | |
| `SMARTHOST_DB_SSLMODE` | app, webhook-worker, validator, delivery, bootstrap | no | 1 | `disable` | libpq `sslmode`. `disable` is acceptable only on the internal network. |
| `POSTGRES_USER` | postgres, bootstrap | no | 1 | `postgres` | Administrative role. It is used only by the bootstrap, never by services. |
| `POSTGRES_PASSWORD` | postgres, bootstrap | **yes** | 1 | | |
| `POSTGRES_DB` | postgres | no | 1 | `smarthost` | Must equal `SMARTHOST_DB_NAME`. |
| `SMARTHOST_DB_OWNER_USER` | app, bootstrap | no | 1 | `smarthost_owner` | Schema-owner role, used **only** by Doctrine migrations. |
| `SMARTHOST_DB_OWNER_PASSWORD` | app, bootstrap | **yes** | 1 | | |
| `APP_DB_USER` | app, bootstrap | no | 1 | `smarthost_app` | Symfony web and scheduled-command role (DML only). |
| `APP_DB_PASSWORD` | app, bootstrap | **yes** | 1 | | |
| `APP_WEBHOOK_DB_USER` | webhook-worker, bootstrap | no | 1 | `smarthost_webhook` | Webhook-worker role. |
| `APP_WEBHOOK_DB_PASSWORD` | webhook-worker, bootstrap | **yes** | 1 | | |
| `VALIDATOR_DB_USER` | validator, bootstrap | no | 1 | `smarthost_validator` | Python role. No schema-altering privileges. |
| `VALIDATOR_DB_PASSWORD` | validator, bootstrap | **yes** | 1 | | |
| `DELIVERY_DB_USER` | delivery, bootstrap | no | 1 | `smarthost_delivery` | Go role. No schema-altering privileges. |
| `DELIVERY_DB_PASSWORD` | delivery, bootstrap | **yes** | 1 | | |

## Symfony application (`app`, `webhook-worker`)

| Variable | Consumers | Secret | Phase | Example | Meaning |
|---|---|---|---|---|---|
| `APP_ENV` | app, webhook-worker | no | 1 | `dev` | Symfony environment: `dev`, `test` or `prod`. |
| `APP_SECRET` | app, webhook-worker | **yes** | 1 | | Symfony kernel secret (CSRF, signed URIs). |
| `TRUSTED_PROXIES` | app, webhook-worker | no | 1 | `10.89.20.0/24` | CIDR of nginx: the `smarthost-internal` Podman network (created by `infra/podman/smarthost-pod.sh.in`). |
| `MAILER_DSN` | app | **yes** | 2 | | Symfony Mailer transport for Smarthost's own low-volume notifications only. Never used for tracked sends. |
| `APP_API_RATE_LIMIT_PER_MINUTE` | app | no | 2 | `600` | Default per-API-key request limit. |
| `APP_API_MAX_REQUEST_BYTES` | app | no | 2 | `10485760` | Request body limit (10 MiB, 413 above it). It is not raised to fit large send jobs. |
| `APP_SEND_JOB_MAX_RECIPIENTS` | app | no | 2 | `10000` | Maximum recipients per send job. It may lower, but not raise, the contract ceiling of 10000. |
| `APP_SEND_JOB_MAX_RECIPIENTS_PER_BATCH` | app | no | 2 | `500` | Maximum recipients per upload request. It may lower, but not raise, the contract ceiling of 500. |
| `APP_ENCRYPTION_KEYS` | app, webhook-worker | **yes** | 2 | | Application keyring for webhook signing secrets, in the form `key_id:base64key[,…]`. The first key encrypts; any listed key decrypts. |
| `APP_WEBHOOK_MAX_ATTEMPTS` | webhook-worker | no | 7 | `8` | Retry limit before a delivery becomes `failed`. |
| `APP_WEBHOOK_TIMEOUT_SECONDS` | webhook-worker | no | 7 | `10` | Per-attempt HTTP timeout. |
| `APP_WEBHOOK_POLL_INTERVAL_SECONDS` | webhook-worker | no | 7 | `5` | Polling fallback when no NOTIFY arrives. |
| `APP_WEBHOOK_LEASE_SECONDS` | webhook-worker | no | 7 | `60` | Delivery lease. It must exceed the HTTP timeout, and long attempts renew it. |
| `APP_WEBHOOK_RETRY_BASE_SECONDS` | webhook-worker | no | 7 | `60` | Base for exponential backoff. |
| `APP_WEBHOOK_RETRY_MAX_SECONDS` | webhook-worker | no | 7 | `21600` | Backoff ceiling. |
| `APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS` | webhook-worker | no | 7 | `webhook-receiver` | Development and test only: comma-separated exact host names that may resolve to private addresses (the local client receiver fixture). Every other webhook destination must resolve to public addresses only (SSRF policy). A non-empty value with `SMARTHOST_ENV=production` is a startup error. |
| `APP_WEBHOOK_SECRET_OVERLAP_HOURS` | app, webhook-worker | no | 2 | `24` | How long a rotated-out signing secret keeps producing a second signature. |
| `APP_DOMAIN_VERIFICATION_RECHECK_HOURS` | app | no | 2 | `24` | How often pending sending domains are re-checked in DNS. |
| `APP_RETENTION_STAGED_CONTENT_DAYS` | app | no | 2 | `7` | Purge period for rendered content of abandoned, cancelled, suppressed or permanently failed recipients. Abandoned collecting jobs are cancelled. |
| `APP_RETENTION_VALIDATION_DAYS` | app | no | 2 | | Validation history. Empty means no automatic deletion until the compliance policy sets it. |
| `APP_RETENTION_MESSAGE_METADATA_DAYS` | app | no | 2 | | Message metadata and events. Empty means no automatic deletion. |
| `APP_RETENTION_TRACKING_DAYS` | app | no | 6 | | Lifetime of tracking tokens and link mappings. After it, tokens are rejected as expired. Empty means no expiry. |
| `APP_RETENTION_SUPPRESSIONS_DAYS` | app | no | 5 | | Lifted or expired suppressions. Empty means no automatic deletion. |
| `APP_RETENTION_UNMATCHED_DSN_DAYS` | app | no | 5 | | Resolved or dismissed unmatched DSNs. Empty means no automatic deletion. |
| `APP_RETENTION_AUDIT_LOG_DAYS` | app | no | 2 | | Empty means no automatic deletion. |
| `APP_RETENTION_USAGE_RECORDS_DAYS` | app | no | 2 | | Empty means no automatic deletion. |
| `APP_RETENTION_WEBHOOK_DELIVERIES_DAYS` | app | no | 7 | | Webhook delivery history (`webhook_deliveries` and their delivered outbox events). Empty means no automatic deletion: rows stay durable until a production retention policy sets a period. Policy only; nothing deletes them yet. |
| `APP_ADMIN_EMAIL` | app | no | 6 | `admin@smarthost-dev.test` | The administrator's email address. It can always request a dashboard sign-in link (its account is created on first sign-in) and receives the ADMIN role, which holds every permission, at every sign-in. Empty disables this. |
| `APP_MAIL_FROM` | app | no | 6 | `no-reply@smarthost-dev.test` | Sender address of the dashboard sign-in emails (display name "Catto Mail Smarthost"). Its domain should be DKIM-signed by OpenDKIM. |
| `APP_LOGIN_LINK_TTL_SECONDS` | app | no | 6 | `900` | Lifetime of an emailed sign-in link. Each link works once. |
| `APP_MAIL_SUBMISSION_HOST` | app | no | 6 | `postfix` | Postfix submission host for the web application's own mail (sign-in links). |
| `APP_MAIL_SUBMISSION_PORT` | app | no | 6 | `587` | Authenticated submission port (STARTTLS, OpenDKIM milter). |
| `APP_MAIL_SUBMISSION_USERNAME` | app, postfix | no | 6 | `smarthost-app` | SASL account of the web application on Postfix submission (separate from the delivery daemon's). |
| `APP_MAIL_SUBMISSION_PASSWORD` | app, postfix | **yes** | 6 | | SASL password for that account. |

## Python validator (`validator`)

| Variable | Consumers | Secret | Phase | Example | Meaning |
|---|---|---|---|---|---|
| `VALIDATOR_WORKER_ID` | validator | no | 3 | | Written to `claimed_by`. Empty means the container hostname. |
| `VALIDATOR_CHUNK_SIZE` | validator | no | 3 | `250` | Addresses claimed per batch. The spec recommends 100–500. |
| `VALIDATOR_LEASE_SECONDS` | validator | no | 3 | `300` | Claim lease. It is renewed while work continues. |
| `VALIDATOR_POLL_INTERVAL_SECONDS` | validator | no | 3 | `10` | Polling fallback when no NOTIFY arrives. |
| `VALIDATOR_GLOBAL_CONCURRENCY` | validator | no | 3 | `20` | Maximum concurrent network checks. |
| `VALIDATOR_PER_DOMAIN_CONCURRENCY` | validator | no | 3 | `2` | Maximum concurrent probes per recipient domain. |
| `VALIDATOR_PER_MX_CONCURRENCY` | validator | no | 3 | `2` | Maximum concurrent connections per MX host. |
| `VALIDATOR_MAX_ATTEMPTS` | validator | no | 3 | `4` | Attempts allowed before an address is finalised as `temporarily_unverifiable`. |
| `VALIDATOR_RETRY_BASE_SECONDS` | validator | no | 3 | `300` | Base for exponential backoff. |
| `VALIDATOR_RETRY_MAX_SECONDS` | validator | no | 3 | `7200` | Backoff ceiling. |
| `VALIDATOR_DNS_RESOLVERS` | validator | no | 3 | | Comma-separated resolver IP addresses (port 53). Empty means the system resolver. |
| `VALIDATOR_DNS_TIMEOUT_SECONDS` | validator | no | 3 | `5` | |
| `VALIDATOR_SMTP_PROBE_ENABLED` | validator | no | 3 | `false` | Master switch for RCPT probing. |
| `VALIDATOR_SMTP_ROUTE_OVERRIDE` | validator | no | 3 | `fake-smtp:2525` | Development/test only. When set, every probe connects here (`host:port`). Must be empty in production. Outside production, `VALIDATOR_SMTP_PROBE_ENABLED=true` requires it, so development and test never probe real mail servers. |
| `VALIDATOR_SMTP_HELO_HOSTNAME` | validator | no | 3 | `validator.smarthost.localhost` | EHLO name. |
| `VALIDATOR_SMTP_MAIL_FROM` | validator | no | 3 | `validator@bounce.smarthost.localhost` | Envelope sender for probes. |
| `VALIDATOR_SMTP_CONNECT_TIMEOUT_SECONDS` | validator | no | 3 | `10` | |
| `VALIDATOR_SMTP_COMMAND_TIMEOUT_SECONDS` | validator | no | 3 | `30` | |

## Go delivery daemon (`delivery`)

| Variable | Consumers | Secret | Phase | Example | Meaning |
|---|---|---|---|---|---|
| `DELIVERY_WORKER_ID` | delivery | no | 4 | | Written to `claimed_by`. Empty means the container hostname. |
| `DELIVERY_POLL_INTERVAL_SECONDS` | delivery | no | 4 | `5` | Polling fallback when no NOTIFY arrives. |
| `DELIVERY_LEASE_SECONDS` | delivery | no | 4 | `300` | Send-job lease. It is renewed while the job is processed. It is also the age after which a DSN spool claim left in `processing/` (crashed worker) is reclaimed. |
| `DELIVERY_POSTFIX_SUBMISSION_HOST` | delivery | no | 4 | `postfix` | Postfix on the internal network. |
| `DELIVERY_POSTFIX_SUBMISSION_PORT` | delivery | no | 4 | `587` | Authenticated submission port, to which the OpenDKIM milter applies. |
| `DELIVERY_GLOBAL_CONCURRENCY` | delivery | no | 4 | `10` | Concurrent submissions. |
| `DELIVERY_PER_DOMAIN_CONCURRENCY` | delivery | no | 4 | `2` | Concurrent submissions per recipient domain. |
| `DELIVERY_PER_DOMAIN_RATE_PER_MINUTE` | delivery | no | 4 | `60` | Submission rate cap per recipient domain. |
| `DELIVERY_DEFERRAL_BACKOFF_SECONDS` | delivery | no | 4 | `900` | Provider backoff after repeated deferrals. |
| `DELIVERY_DSN_NOTIFY` | delivery | no | 4 | `FAILURE,DELAY` | RFC 3461 NOTIFY parameter. |
| `DELIVERY_DSN_RET` | delivery | no | 4 | `HDRS` | RFC 3461 RET parameter. |
| `DELIVERY_FILE_POLL_INTERVAL_SECONDS` | delivery | no | 4 | `2` | Poll interval for the shared log and DSN spool. |
| `DELIVERY_RECONCILE_INTERVAL_SECONDS` | delivery | no | 4 | `300` | How often reconciliation runs. |
| `DELIVERY_RECONCILE_GRACE_SECONDS` | delivery | no | 4 | `3600` | Minimum time after a queue id disappears before any conclusion is drawn. |
| `DELIVERY_RECONCILE_MIN_SNAPSHOTS` | delivery | no | 4 | `2` | Number of consecutive fresh snapshots (≥ 2) that must lack the queue id. |
| `DELIVERY_SOFT_BOUNCE_SUPPRESSION_THRESHOLD` | delivery | no | 5 | `3` | Consecutive recipient-scope soft bounces of an address (distinct messages, all clients; D-30) that trigger a global `repeated_soft_bounce` suppression. |
| `DELIVERY_SOFT_BOUNCE_SUPPRESSION_WINDOW_DAYS` | delivery | no | 5 | `30` | Rolling window for that count, and the lifetime of the resulting temporary suppression. |
| `DELIVERY_DSN_RETENTION_DAYS` | delivery | no | 5 | `7` | Transient retention of processed DSN files in the spool's `done/` directory. |

## Postfix (`postfix`)

| Variable | Consumers | Secret | Phase | Example | Meaning |
|---|---|---|---|---|---|
| `POSTFIX_MYHOSTNAME` | postfix | no | 1 | `smarthost.localhost` | `myhostname`. |
| `POSTFIX_RELAYHOST` | postfix | no | 1 | `[mailpit]:1025` | In development, Mailpit. In production it is empty, which is allowed only when `SMARTHOST_LIVE_DELIVERY_ENABLED=true`. |
| `POSTFIX_TLS_CERT_FILE` | postfix | no | 1 | `/run/secrets/postfix_tls_cert` | Path to the mounted certificate. |
| `POSTFIX_TLS_KEY_FILE` | postfix | no | 1 | `/run/secrets/postfix_tls_key` | Path to the mounted private key. |
| `POSTFIX_MESSAGE_SIZE_LIMIT` | postfix | no | 1 | `10240000` | `message_size_limit` in bytes. |
| `POSTFIX_LOG_RETENTION_DAYS` | postfix | no | 4 | `14` | Rotated log files older than this are deleted. It must exceed the longest tolerated Go outage. |
| `POSTFIX_QUEUE_SNAPSHOT_INTERVAL_SECONDS` | postfix, delivery | no | 4 | `60` | Interval between queue snapshots. Go treats the newest snapshot as stale (no reconciliation conclusions) when it is older than three intervals. |
| `POSTFIX_QUEUE_SNAPSHOT_RETENTION_COUNT` | postfix | no | 4 | `60` | Number of most recent snapshots kept. |

## OpenDKIM (`opendkim`)

| Variable | Consumers | Secret | Phase | Example | Meaning |
|---|---|---|---|---|---|
| `OPENDKIM_KEY_DIR` | opendkim | no | 1 | `/run/secrets/opendkim` | Mounted directory of DKIM private keys. The path is not secret, but the contents are mounted secrets and visible to OpenDKIM only. |
| `OPENDKIM_TABLES_DIR` | opendkim | no | 1 | `/etc/opendkim/tables` | KeyTable and SigningTable that map verified domains with active DKIM to selectors and keys. |

## Development and test services

| Variable | Consumers | Secret | Phase | Example | Meaning |
|---|---|---|---|---|---|
| `MAILPIT_UI_BIND` | mailpit | no | 1 | `127.0.0.1:8026` | Mailpit web UI. Loopback or a development interface only. It defaults to 8026 because 127.0.0.1:8025 is often held by other local Mailpit instances on the same Podman machine. |
| `MAILPIT_SMTP_PORT` | mailpit | no | 1 | `1025` | Internal-network SMTP port. Not published. |
| `FAKE_SMTP_PORT` | fake-smtp | no | 1 | `2525` | Internal-network port of the SMTP simulator. |

## Reverse proxy (`proxy`, nginx)

| Variable | Consumers | Secret | Phase | Example | Meaning |
|---|---|---|---|---|---|
| `PROXY_HTTPS_BIND` | proxy | no | 1 | `127.0.0.1:8443` | Published HTTPS listener. In production it is `0.0.0.0:443`. |
| `PROXY_SERVER_NAME` | proxy | no | 1 | `localhost` | nginx `server_name`. Must match the host in `SMARTHOST_PUBLIC_BASE_URL`. |
| `PROXY_FASTCGI_ADDRESS` | proxy | no | 1 | `symfony-app:9000` | PHP-FPM FastCGI address on the internal network. There is no separate PHP HTTP application server. |
| `PROXY_TLS_CERT_FILE` | proxy | no | 1 | `/run/secrets/proxy_tls_cert` | Path to the mounted certificate. |
| `PROXY_TLS_KEY_FILE` | proxy | no | 1 | `/run/secrets/proxy_tls_key` | Path to the mounted private key. |

The nginx configuration lives under `infra/` and is the same in development and production.
