# catto-mail production topology

**Status:** specification 2.11 (Phase 8 repository-side production readiness; Phase 9 SaaS operations, see [onboarding.md](onboarding.md); Phase 10 operator self-service). **To install, start with [VPS-INSTALL.md](VPS-INSTALL.md)**; the components are described in [components.md](components.md). The operator's
procedures are in [runbook.md](runbook.md); the configuration contract is
[`docs/contracts/environment.md`](../contracts/environment.md) (sections *Production deployment*,
*Production profile* and *Retention settings in production*).

## 1. Development and production side by side

| | Development (D-35) | Production (Phase 8) |
|---|---|---|
| Script | `infra/podman/smarthost-pod.sh.in` | `infra/podman/smarthost-production.sh.in` |
| Commands | `smarthostctl <command>` | `smarthostctl prod <command>` |
| Shape | one pod `smarthost`; every member shares one network namespace and loopback | eight standalone containers `<instance>-<service>`, each in its own network namespace |
| Networks | `smarthost-internal` (internal, no route out) | `<instance>-internal` (internal), `<instance>-ingress` (internal; nginx and Postfix only) and `<instance>-egress` (routed) |
| Services | + Mailpit, fake SMTP | no Mailpit, no fake SMTP |
| Outbound mail | relayed to Mailpit (capture mode) | held until activation, then direct MX delivery |
| Public listeners | published pod ports `127.0.0.1:8443` (nginx), `127.0.0.1:8026` (Mailpit UI) | no published ports: the systemd socket `<instance>-ingress.socket` binds `0.0.0.0:443` and `0.0.0.0:25` and hands them to nginx (§2.1) |
| TLS | disposable self-signed (`smarthostctl secrets`) | operator certificates (`smarthostctl prod tls set`) |
| DKIM | disposable key (`smarthostctl dkim-dev-key`) | production keys generated in OpenDKIM (`smarthostctl prod dkim`) |
| Lifecycle | persistent pod, `smarthost.service` | persistent containers, `smarthost.service` (lingering user); nginx under `<instance>-ingress.service` |

Both are rootless Podman with persistent objects: `stop`/`start`/`restart` keep every container,
only `recreate`/`upgrade` replace them, and nothing ever deletes a named volume. The development
pod is unchanged by Phase 8.

**Why production is not a pod.** The members of a pod share one network namespace, so every
member would have the same connectivity, including the Internet. Production gives each component
only what it needs. The services keep fixed addresses on the internal network (`.10`–`.17` of
`SMARTHOST_INTERNAL_SUBNET`), and each container gets `/etc/hosts` entries for `postgres`,
`opendkim`, `postfix` and `symfony-app`. This means service names resolve the same way with any
aardvark-dns version, even for containers on two networks.

## 2. Network matrix

| Component | Networks | Inbound | Outbound (production) |
|---|---|---|---|
| nginx | internal + ingress | **HTTPS 443 and SMTP 25 from the Internet** on the sockets of `<instance>-ingress.socket` (`PROXY_HTTPS_BIND`, `POSTFIX_SMTP_BIND`) | FastCGI to `symfony-app:9000`; SMTP to `postfix-ingress:25` with the PROXY protocol |
| Postfix | internal + ingress + **egress** | **SMTP 25 from nginx only** (`postfix-ingress:25`, PROXY protocol: the real client address), bounce domain only; submission 587 from the delivery daemon and the application (SASL + TLS) | **SMTP 25 to destination MX hosts** (DNS through the egress network); milter to `opendkim:8891` |
| webhook worker | internal + **egress** | – | **HTTPS to client webhook endpoints** (public addresses only: SSRF guard, address pinning, no redirects); PostgreSQL |
| validator | internal + **egress** | – | **DNS**; **SMTP 25 to MX hosts** only when `VALIDATOR_SMTP_PROBE_ENABLED=true`; PostgreSQL |
| Symfony application | internal + **egress** | FastCGI from nginx | **DNS** (sending-domain TXT verification); PostgreSQL; Postfix submission 587 (sign-in mail) |
| delivery daemon (Go) | internal | – | PostgreSQL; Postfix submission 587; the shared observability (read-only) and DSN spool volumes |
| OpenDKIM | internal | milter 8891 from Postfix | – |
| PostgreSQL | internal | 5432 from the services and the DB tasks | – |

No container publishes a port. The preflight (`smarthostctl prod preflight --section exposure`)
fails on any published port, on a container whose networks differ from this matrix, and on a TLS
or DKIM private key mounted anywhere but its owner. `--section ingress` checks the listeners
(§2.1).

### Routes and paths

```text
Inbound
  Internet --HTTPS 443--> [ingress socket] nginx --FastCGI--> symfony-app --> postgres
  Internet --SMTP 25----> [ingress socket] nginx --PROXY protocol--> postfix (bounce domain only)
                                           --virtual(8)--> DSN spool --> delivery (Go) --> postgres

Outbound
  delivery (Go) --587 SASL/TLS--> postfix --milter--> opendkim
                                  postfix --SMTP 25 (egress)--> recipient MX hosts
  symfony-app (sign-in links) --587--> postfix --> MX of the operator's mailbox
  webhook-worker --HTTPS (egress)--> client webhook endpoints
  validator --DNS, SMTP 25 probes (egress)--> recipient domains' MX hosts
  symfony-app --DNS (egress)--> sending-domain TXT verification
```

### Host firewall

`smarthostctl prod firewall` prints an nftables ruleset for review:
- **Inbound:** SSH (`SMARTHOST_SSH_PORT`), 25 and 443 only.
- **Outbound for the service user:** DNS, 25, 80 and 443. Rootless containers reach the Internet
  through processes of this user, so the limit applies to all containers together.

Per-container egress is enforced by the Podman networks.

### 2.1 Ingress: client addresses are preserved

**Why.** Several controls key on the client's address:
- the dashboard sign-in link limiter;
- the tracking-request limiter;
- the API-key failure limiter;
- nginx's access log and Postfix's log and SMTP policy.

Rootless Podman's port forwarding replaces the client address on a published port: rootlessport,
and pasta for loopback traffic. Measured on this project's development machine, two clients
(`127.0.0.7`, `127.0.0.9`) reached a published container as one address (`10.89.3.2`, the
container's own). Every client would then share one limiter bucket. Worse, that address lies
inside the internal network, so a `TRUSTED_PROXIES` covering it would have let any client choose
its own address with `X-Forwarded-For`.

**Design: socket activation.** The service user's systemd binds the public listeners in the
host's own network namespace (`<instance>-ingress.socket`: `PROXY_HTTPS_BIND`,
`POSTFIX_SMTP_BIND`). `<instance>-ingress.service` starts the persistent nginx container with
both sockets: Podman passes them in (`LISTEN_FDS=2`), and nginx inherits them (`NGINX=3;4;`; its
production templates listen on exactly those two addresses). nginx calls `accept()` on the
kernel's own listening sockets, so every connection carries the real client address. No proxy
process stands in between. Nothing is published, and nginx keeps no Internet egress.
- **HTTPS:** nginx terminates TLS and passes `$remote_addr` to PHP-FPM as `REMOTE_ADDR`.
- **SMTP:** an nginx `stream` server passes each connection byte for byte to Postfix over the
  internal `<instance>-ingress` network (only nginx and Postfix). It starts with a PROXY-protocol
  header carrying the client address. Postfix's port-25 listener (`postfix-ingress:25`,
  `smtpd_upstream_proxy_protocol = haproxy`) requires that header and logs and checks that
  address. STARTTLS stays Postfix's own. No other container can reach that listener, so only
  nginx can assert a client address.

**Request path and the trusted hop:**

```text
Internet client 198.51.100.7
  -> host kernel, socket of <instance>-ingress.socket (0.0.0.0:443 / 0.0.0.0:25)
  -> nginx accept(): peer 198.51.100.7            <- the trusted remote address is established here
       HTTPS: FastCGI REMOTE_ADDR=198.51.100.7 -> Symfony Request::getClientIp() = REMOTE_ADDR
              (TRUSTED_PROXIES is empty: X-Forwarded-For/-Proto from clients are ignored)
       SMTP:  PROXY TCP4 198.51.100.7 ... -> Postfix postfix-ingress:25 -> "connect from ...[198.51.100.7]"
```

The kernel and nginx's `accept()` establish the address. Symfony trusts the FastCGI
`REMOTE_ADDR` because only nginx talks FastCGI to it. Postfix trusts the PROXY header because
only nginx can reach its ingress listener. HTTPS detection uses FastCGI `HTTPS=on` from nginx, not
`X-Forwarded-Proto`.

**Why this mechanism.** Alternatives were judged on their measured behaviour:
- **Published ports** (rootlessport, the default for rootless bridge networks): collapse every
  client to one address. Measured.
- **pasta network mode:** keeps external addresses, but rewrites loopback-originated connections
  (measured), so it cannot be proven on the host itself. A pasta container cannot join a Podman
  network either, so nginx would need Internet egress and Unix sockets to FPM and Postfix.
- **Host network for nginx:** gives nginx the whole host network and every local port, and it
  cannot reach the rootless internal network.
- **Rootful Podman, or a root-owned port forwarder:** gives up rootless.

Socket activation is standard systemd and Podman (Podman ≥ 4 passes `LISTEN_FDS` to `podman
start`). It keeps the network matrix, and it is provable on the host with two loopback source
addresses: `smarthostctl prod ingress-check`, part of `preflight --activation`.

**Operating it.**
- `smarthost.service` (the topology script) starts and stops the socket and nginx with the other
  containers. `stop` releases 25/443.
- nginx has no Podman restart policy. If it crashes, systemd restarts it with the same sockets.
- Use `prod replace nginx` or `prod restart`, never `podman restart` or `podman start` on nginx:
  started without the sockets, it serves nothing, and the preflight reports it.

### 2.2 Ports below 1024 for a rootless service

The socket unit runs as the unprivileged service user, so binding 443 and 25 needs
`net.ipv4.ip_unprivileged_port_start=25` on the host (runbook §1). The setting is system-wide: any
local user may then bind 25–1023, while 1–24 (SSH on 22) stay privileged. On a dedicated host
with no other interactive users this is accepted. While the socket unit is active it holds both
ports, so no one else can take them.

`smarthostctl prod preflight --section host` checks the running value against the configured
binds, and checks that a `sysctl.d` file persists it. `install`, `create`, `start`, `recreate`,
`replace` and `upgrade` run that check first; on failure they print the remediation and create
or start nothing. Unprivileged binds, like the rehearsal's `127.0.0.1:18443`, need no setting.

## 3. Live-delivery states

| State | `SMARTHOST_ENV` / `SMARTHOST_LIVE_DELIVERY_ENABLED` | Postfix | Go delivery daemon |
|---|---|---|---|
| capture (development) | development, false | relays everything to Mailpit | claims and submits |
| **held** (production, installed) | production, false | no relayhost; `default_transport = retry:…`: outbound recipients are refused temporarily at submission (`450 … live delivery is not activated`) and anything queued stays deferred, except mail from `APP_MAIL_FROM` (dashboard sign-in) | claims no send jobs; DSN, log ingestion and reconciliation run |
| **live** (production, activated) | production, true | direct MX delivery | claims and submits within the warm-up ceiling |
| **paused** (any, operator) | flag `control/outbound-paused` in the observability volume | `defer_transports = smtp`: nothing leaves the queue | stops claiming and starting submissions |

Activation is `smarthostctl prod live-enable --operator … --note …`. It runs the activation
preflight, writes the audit log, sets `SMARTHOST_LIVE_DELIVERY_ENABLED=true` and recreates Postfix
and the delivery daemon. `recreate` and `install` refuse to (re)create a live configuration whose
activation preflight fails.

## 4. Where state lives

| Data | Volume / location | Authoritative? | Backup |
|---|---|---|---|
| PostgreSQL | `<instance>-postgres-data` | **yes**: everything durable | `pg_dump` (`prod backup`) |
| DKIM private keys, KeyTable/SigningTable | `<instance>-opendkim-keys`, `-tables` | **yes**: lost keys mean new DNS records | volume export |
| Configuration and secrets, incl. `APP_ENCRYPTION_KEYS` (webhook signing secrets) and DB passwords | `infra/.env` on the host (0600) | **yes** | copied into the backup (encrypt it) |
| TLS certificates and keys | Podman secrets `<instance>-*-tls-*` | renewable from the CA/ACME client | exported when Podman allows, else keep the source files |
| Postfix queue | `<instance>-postfix-queue` | transient (mail in flight) | optional (`--with-queue`, pause first) |
| DSN spool, Postfix logs, queue snapshots | `<instance>-dsn-spool`, `-postfix-observability` | transient (parsed into PostgreSQL) | optional |
| Images | `localhost/smarthost-*:<tag>` | rebuildable from the release tag | – |

## 5. Launch decisions (current)

| Decision | In force | How to change it later |
|---|---|---|
| **IPv4-only delivery** | Postfix uses `inet_protocols = ipv4`, and all binds are IPv4. The sending identity is `SMARTHOST_PUBLIC_IPV4` with its A, PTR and SPF. | IPv6 is a second sending identity. It needs its own forward DNS (AAAA for the mail hostname), PTR, SPF authorisation (`ip6:`) and reputation monitoring with the mailbox providers. Add it as a separate, deliberate change. |
| **Validator SMTP probing off** | `VALIDATOR_SMTP_PROBE_ENABLED=false`. Validation still runs every other stage: normalisation, syntax, typo suggestions, domain DNS (MX, null MX, address fallback), the disposable-domain and role-account signals, and classification. Only the mailbox-level evidence (RCPT and accept-all) is absent. | Enabling RCPT probing is an explicit operator decision: it makes outbound SMTP connections from the sending IP that providers can see and that can affect its reputation. Set it to `true` deliberately, then `prod replace validator`. |
| **Retention unset** | Every long-term `APP_RETENTION_*` is empty, which means **no automatic deletion** (contract, *Retention settings in production*). | Set a period per category after an operational and compliance decision (runbook §16). |
| **Client addresses preserved** | Socket-activated ingress (§2.1). `TRUSTED_PROXIES` is empty. | A proxy or load balancer in front of the host would itself become the trusted hop. That needs a configuration change (its address in `TRUSTED_PROXIES`, and PROXY-protocol handling) and a new decision. |
