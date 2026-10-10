# catto-mail components

Specification 2.11. One canonical name per component, used everywhere: the dashboard, the
help pages, the diagnostics and these documents. Container names use the default instance
name `smarthost` (`SMARTHOST_INSTANCE`); every object is owned by the service user
`cattomail` and runs in rootless Podman.

## The eight services

| Component | Container | Process | Purpose |
|---|---|---|---|
| **Catto-mail Web Application** | `smarthost-symfony-app` | PHP-FPM running Symfony | Dashboard, client API `/v1`, public API docs `/docs/api`, tracking `/t/…`, re-permission pages `/p/…`, sign-in. Records work; never sends mail or webhooks; never runs host commands. |
| **Catto-mail Webhook Worker** | `smarthost-webhook-worker` | `php bin/console smarthost:webhook:work` | Sends signed webhooks to client systems with retries. |
| **Catto-mail Email Validator** | `smarthost-validator` | `python -m smarthost_validator` | Validates addresses of validation jobs. |
| **Catto-mail Delivery Daemon** | `smarthost-delivery` | `smarthost-delivery` (Go) | Turns send jobs into messages, checks suppressions, submits to Postfix, follows Postfix's log and queue, processes bounces and complaints. |
| **Postfix Mail Transfer Agent** | `smarthost-postfix` | Postfix | Delivers mail to recipients' mail servers; receives bounces and complaints for the bounce domain. |
| **OpenDKIM Signing Service** | `smarthost-opendkim` | OpenDKIM (milter) | Signs every outgoing message; the only holder of DKIM private keys. |
| **PostgreSQL Database** | `smarthost-postgres` | PostgreSQL 16 | All state. |
| **nginx Frontend** | `smarthost-nginx` | nginx | Accepts HTTPS (443) and SMTP (25) from the Internet through the systemd ingress socket; FastCGI to the web application; SMTP to Postfix with the PROXY protocol. |

### Inputs, outputs, network and data

| Component | Input | Output | Network | Persistent data |
|---|---|---|---|---|
| Web Application | HTTPS requests (through nginx) | rows in the database; webhook outbox events; sign-in mail (via Postfix submission) | internal; egress (DNS for domain verification) | none of its own (the database); `var/` holds uploads for at most an hour |
| Webhook Worker | `webhook_events` outbox | HTTPS POSTs to client endpoints; delivery records | internal; egress (HTTPS) | none (database) |
| Email Validator | `validation_jobs` / `validation_addresses` (leased) | results and evidence; usage records; webhook events | internal; egress (DNS; SMTP only if probing is enabled) | none (database) |
| Delivery Daemon | queued `send_jobs` (leased); Postfix log, queue snapshots; bounce spool | messages and events; suppressions; SMTP submissions to Postfix; heartbeats | internal only | reads `smarthost-postfix-observability` (read-only) and `smarthost-dsn-spool` |
| Postfix | submissions on 587 (internal); SMTP on 25 from nginx | delivery to the Internet on 25; log and queue snapshots; DSNs into the spool | internal; ingress; egress | `smarthost-postfix-queue`, `smarthost-postfix-observability`, `smarthost-dsn-spool` |
| OpenDKIM | milter requests from Postfix | signatures | internal | `smarthost-opendkim-keys`, `smarthost-opendkim-tables` |
| PostgreSQL | SQL from the four application components (one role each) | — | internal | `smarthost-postgres-data` |
| nginx | client connections on the host's 443 and 25 (inherited sockets) | FastCGI; SMTP with PROXY protocol | internal; ingress | none |

### Dependencies

```text
PostgreSQL ← Web Application, Webhook Worker, Email Validator, Delivery Daemon
OpenDKIM   ← Postfix (milter)
Postfix    ← Delivery Daemon (587), Web Application (587, sign-in mail), nginx (25, PROXY)
nginx      ← the ingress socket (systemd); → Web Application, Postfix
```

The topology script starts them in this order: PostgreSQL, the database tasks (role
bootstrap, migrations, grants), OpenDKIM, Postfix, the web application, the webhook worker,
nginx (through the ingress socket), the validator, the delivery daemon.

## On the host (outside the containers)

| Part | What | Runs as |
|---|---|---|
| `smarthost.service` | Starts the containers at boot (linked into `default.target` of the service user) and stops them at shutdown; never removes them. | `cattomail` (systemd user) |
| `smarthost-ingress.socket` / `.service` | Bind `0.0.0.0:443` and `0.0.0.0:25` and hand them to nginx, so client addresses are preserved. | `cattomail` |
| `smarthost-host-agent.service` | The **host agent**: reports host-side checks (Ubuntu, Podman, lingering, units, disk, memory, clock, containers, backups, boot recovery, the production preflight) and carries out dashboard requests. | `cattomail` |
| `smarthost-backup.timer` | Daily backup (`BACKUP_SCHEDULE`), off-host copy. | `cattomail` |
| `smarthost-tls-renew.timer` | Daily Let's Encrypt renewal check (DNS-01, `lego`). | `cattomail` |
| `smarthost-reputation-evaluate.timer` | Client reputation metrics and alerts, every 15 minutes. | `cattomail` |
| `smarthost-postfix-queue-snapshot.timer`, `smarthost-postfix-logrotate.timer` | Postfix queue snapshots (reconciliation) and log rotation. | `cattomail` |
| `/etc/sysctl.d/60-catto-mail-ports.conf` | `net.ipv4.ip_unprivileged_port_start=25`: lets the service user bind 25 and 443. | root (installer) |
| nftables rules | Optional host firewall generated by `smarthostctl prod firewall`. | root |

**Not used, not installed:** a DNS server (BIND is **not** required: public DNS stays with
your DNS provider, and catto-mail only queries DNS), Ubuntu's own Postfix, PHP, PostgreSQL,
Go or Python application packages. Everything application-specific is inside the images.

## Networks

| Network | Members | Internet |
|---|---|---|
| `smarthost-internal` | every service | none |
| `smarthost-ingress` | nginx, Postfix | none (carries SMTP from nginx to Postfix) |
| `smarthost-egress` | Postfix, validator, webhook worker, web application | yes (NAT through the host) |

No container publishes a port. Inbound traffic reaches only nginx, through the systemd socket.

## Volumes

`smarthost-postgres-data`, `smarthost-postfix-queue`, `smarthost-postfix-observability`,
`smarthost-dsn-spool`, `smarthost-opendkim-keys`, `smarthost-opendkim-tables`. Volumes are
never deleted by the tooling; `prod recreate` and upgrades keep them.

## Podman secrets

`smarthost-proxy-tls-cert`, `smarthost-proxy-tls-key`, `smarthost-postfix-tls-cert`,
`smarthost-postfix-tls-key` (installed by `smarthostctl prod tls set`). All other secrets are
in `infra/.env` (mode 0600) and reach each container only through its own env file.

## Configuration

`infra/.env` holds only the operator's settings, grouped under headings; every other variable of
`docs/contracts/environment.md` is built in. `smarthost_render.py` renders it into one env file per
container, the topology script and the systemd units in `infra/.generated/`. Values changed in
**System › Settings** are stored in PostgreSQL (`setting_overrides`), never in the file: *Apply
changes* asks the host agent to render again with them (`smarthostctl prod settings-apply`) and to
replace only the containers whose settings changed. The rendered `settings.json` (mounted read-only
into the web application) tells the page which values are in force. Every container runs in the
installation's time zone (`TZ`, PostgreSQL `timezone`), `SMARTHOST_TIMEZONE`.
