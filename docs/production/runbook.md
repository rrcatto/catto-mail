# catto-mail production runbook

Specification 2.11 (Phase 8; Phase 9 SaaS operations in section 20; operator self-service in
section 21). **New installations start with [VPS-INSTALL.md](VPS-INSTALL.md)**: the installer
`install-catto-mail` performs sections 1 and 2, and the web application's System setup guides
the rest. This runbook is the reference for every command behind it. Topology and network matrix: [README.md](README.md). Every
production command is `infra/bin/smarthostctl prod <command>` (`prod help` lists them). The
development commands (`smarthostctl start`, `recreate`, ...) refuse a production configuration.

The work falls into four stages, which can happen on different days:

| Stage | Who/where | Sections |
|---|---|---|
| **A. Repository and configuration preparation** | the operator on the production host | 1–7 |
| **B. External DNS and provider actions** | DNS host, IP provider (PTR), CA | 5, 6 |
| **C. Live smoke testing** | the operator, with addresses they own | 8–9 |
| **D. Traffic rollout** | the operator, stage by stage, on evidence | 10 |
| Ongoing operation | | 11–20 |

Placeholders below: `mail.example.com` (public hostname), `mta.example.com` (mail hostname / PTR),
`bounce.example.com` (bounce domain), `203.0.113.10` (the host's public IPv4),
`send.example.com` (a client's sending domain).

---

## 1. First VPS preparation (Ubuntu Server 26.04 LTS)

`install-catto-mail` does all of this (and section 2); the manual equivalent:

1. A VPS with a static public IPv4 address. Your provider must allow outbound TCP 25 (many block
   it by default: ask) and must let you set the **PTR** record of the address.
2. As root:
   ```sh
   apt-get update && apt-get install -y podman git python3 openssl iproute2 nftables uidmap slirp4netns passt rsync openssh-client lego
   adduser --disabled-password --gecos '' cattomail           # the service user (rootless Podman)
   loginctl enable-linger cattomail                           # its services start at boot
   echo 'net.ipv4.ip_unprivileged_port_start=25' > /etc/sysctl.d/60-catto-mail-ports.conf
   sysctl --system                                            # applies it now; the file applies it at every boot
   ```
   Ubuntu 26.04 ships Podman 5.7 (the preflight accepts 4.9 and later).

   **Why the sysctl.** The public listeners are TCP 443 (HTTPS) and TCP 25 (SMTP). The service
   user's own systemd binds them on the host: the socket unit `smarthost-ingress.socket` hands them
   to nginx, so nginx sees each client's real address (README §2.1). Linux reserves ports below
   `net.ipv4.ip_unprivileged_port_start` (default 1024) for privileged processes. Setting it to 25
   lets unprivileged processes bind 25–1023 and keeps 1–24, including SSH on 22, privileged.
   Everything stays rootless: no capability, setuid helper or root daemon is involved.

   **Scope and security.**
   - The setting is **system-wide** for the host's network namespace: every local user, not only
     `cattomail`, may bind ports 25–1023. Container network namespaces are not affected.
   - The risk is a local account squatting 25 or 443 while the ingress socket is stopped, and so
     impersonating the service. On a dedicated host that runs only catto-mail, with no other
     interactive users, this is acceptable.
   - Once started, the ingress socket holds both ports even while nginx restarts. `prod stop`
     releases them until the next `prod start`.
   - Ports below 25 stay privileged, so set no lower value.
3. As `cattomail` (log in as that user, e.g. `machinectl shell cattomail@` or SSH, so that
   `systemctl --user` works):
   ```sh
   systemctl --user enable --now podman.socket
   git clone https://github.com/<owner>/catto-mail.git && cd catto-mail && git checkout v<release>
   infra/bin/smarthostctl prod init-env && $EDITOR infra/.env    # section 2
   infra/bin/smarthostctl prod preflight --section host          # the bootstrap prerequisites: no FAIL
   ```
   `--section host` checks:
   - the OS and Podman ≥ 4.9;
   - the binaries;
   - lingering and `podman.socket`;
   - **`net.ipv4.ip_unprivileged_port_start` ≤ 25**, in force now and persisted in a
     `sysctl.d` file. If it is not, the FAIL line prints the exact command to fix it.

   `prod install`, `create`, `start`, `recreate`, `replace` and `upgrade` run the same check
   first, and stop before creating or starting anything if it fails.
4. Host firewall: `infra/bin/smarthostctl prod firewall > /tmp/catto-mail.nft` (after step 2.2),
   review it, then as root `nft -f /tmp/catto-mail.nft` and persist it in `/etc/nftables.conf`.

## 2. Installation

```sh
infra/bin/smarthostctl prod init-env      # infra/.env from infra/production.env.example, secrets generated here
$EDITOR infra/.env                        # every example.com / 203.0.113.x placeholder, SMARTHOST_IMAGE_TAG=<release>
infra/bin/smarthostctl prod check         # contract + production safety rules: must PASS
infra/bin/smarthostctl prod build         # images localhost/smarthost-*:<SMARTHOST_IMAGE_TAG>, plus the pinned PostgreSQL image
infra/bin/smarthostctl prod tls set proxy.crt proxy.key mta.crt mta.key   # section 4
infra/bin/smarthostctl prod install       # host check, systemd units (start at boot; the ingress socket), containers
infra/bin/smarthostctl prod start         # postgres -> bootstrap, migrations, grants -> services (nginx via the ingress socket) -> healthy
infra/bin/smarthostctl prod status        # containers, the ingress socket and its listen addresses
infra/bin/smarthostctl prod ingress-check # client addresses reach nginx/Symfony and Postfix unchanged (section 7)
```

The installation starts in **held mode**: nothing is delivered to the Internet except the
dashboard sign-in mail. Sign in at `https://mail.example.com/dashboard` with `APP_ADMIN_EMAIL`. The
account is created with ADMIN, which holds `SYSTEM.DELIVERY.CONTROL`, the permission the audited
delivery controls need.

`check` refuses, among others:
- unverified-domain bypass, a webhook private-host allowlist, or any relayhost (Mailpit);
- missing or short secrets, or a malformed keyring;
- a base URL that is not `https://PROXY_SERVER_NAME`;
- reserved or placeholder names, or a non-public `SMARTHOST_PUBLIC_IPV4`;
- a bounce domain equal to the public hostname;
- a non-empty `TRUSTED_PROXIES` (no proxy stands in front of nginx);
- `PROXY_HTTPS_BIND`/`POSTFIX_SMTP_BIND` that are not two different IPv4 `address:port` values;
- overlapping or non-/24 internal, egress and ingress subnets;
- wrong service aliases.

## 3. Secrets

- All secrets live in `infra/.env` on the host: mode 0600, gitignored, generated by `prod init-env`.
  `prod render` copies each secret only into the env file of the containers that consume it.
- Services also accept `X_FILE` for any secret `X` (contract rule 2).
- `APP_ENCRYPTION_KEYS` encrypts the webhook signing secrets. To rotate it, put a new key first and
  keep the old one listed (`new:…,old:…`).
- Every backup contains `infra/.env`. Encrypt backups and keep them off the host.
- Changing a secret: edit `infra/.env`, then `prod recreate`. The DB role passwords are re-applied
  by the bootstrap task at start.

## 4. TLS

- **HTTPS (nginx):** a certificate for `PROXY_SERVER_NAME` from any CA or ACME client. Port 80 is
  not published, so use the DNS-01 challenge, or a standalone HTTP-01 run while you open port 80
  briefly.
- **SMTP (Postfix):** a certificate for `POSTFIX_MYHOSTNAME`, used for STARTTLS on port 25 and
  submission. A publicly trusted certificate is recommended; MTAs accept self-signed ones
  opportunistically.
- **Let's Encrypt (DNS-01, specification 2.11):** set `ACME_EMAIL`, `ACME_DNS_PROVIDER` and
  `ACME_CREDENTIALS_FILE` (VPS-INSTALL.md §5), then `prod tls acme issue`. The daily
  `<instance>-tls-renew.timer` runs `prod tls acme renew`; `prod tls acme status` shows the state.
  `ACME_DNS_PROVIDER=manual` prints the TXT record to create by hand.
- **Temporary certificate:** `prod tls self-signed-bootstrap` (the installer uses it until Let's
  Encrypt is set up; the TLS checks report it as untrusted and live activation is refused).
- **Install or renew any certificate:**
  `prod tls set <proxy.crt> <proxy.key> <postfix.crt> <postfix.key>`. It checks that each
  certificate matches its key, replaces the Podman secrets, and recreates nginx and Postfix only.
- **Check:** the preflight reports validity, names, trust, and expiry within 14 days.
- With another ACME client, call `prod tls set` from its deploy hook.

## 5. DNS identity (external actions)

Run `infra/bin/smarthostctl prod dns-checklist` for the exact records of this configuration:

| Record | Value | Where |
|---|---|---|
| `mail.example.com A` | `203.0.113.10` | DNS |
| `mta.example.com A` | `203.0.113.10` | DNS |
| **PTR** `203.0.113.10` | `mta.example.com` (must resolve back: FCrDNS) | **IP provider** |
| `bounce.example.com MX` | `10 mta.example.com.` | DNS |
| `bounce.example.com TXT` | `v=spf1 ip4:203.0.113.10 -all` (envelope sender of every message) | DNS |
| `mta.example.com TXT` | `v=spf1 ip4:203.0.113.10 -all` (EHLO name) | DNS |
| `<selector>._domainkey.send.example.com TXT` | from `prod dkim dns send.example.com` | the sending domain's DNS |
| `_dmarc.send.example.com TXT` | `v=DMARC1; p=none; rua=mailto:dmarc@send.example.com` to start | the sending domain's DNS |

Each client sending domain is also verified by Smarthost's TXT challenge
(`smarthost:domain:add`, then `smarthost:domain:verify`). DMARC alignment comes from DKIM:
`d=` is the sending domain. The return path is the bounce domain, so SPF aligns only within one
organisational domain. The preflight validates and reports. It never rewrites DNS.

## 6. Production DKIM keys

OpenDKIM is the only container that holds DKIM private keys. They are generated inside it and
never leave its volume. Only the backup exports the volume.

```sh
prod dkim generate send.example.com s2026a     # new 2048-bit key; NOT yet signing; prints the TXT record
# publish the TXT record, wait for DNS, then:
prod dkim activate send.example.com s2026a     # SigningTable; OpenDKIM restarted
prod console smarthost:domain:dkim <client-id> send.example.com active --selector s2026a
prod dkim list                                  # domain, selector, active, key present, bits
prod dkim dns send.example.com                  # the active selector's record (zone-file form too)
```

**Planned rotation:**
1. `generate` a new selector and publish its record.
2. After DNS has propagated: `activate` the new selector and record it with `smarthost:domain:dkim`.
3. Keep the old record published for at least a week, so messages in transit still verify.
4. Remove the old record, then `prod dkim retire send.example.com <old>`. Retiring the active
   selector is refused.

The domain of `APP_MAIL_FROM` needs a key too; it signs the sign-in mail.

## 7. Production preflight

```sh
prod preflight                       # all sections: config, host, runtime, exposure, ingress, tls, dns, dkim, postfix, delivery
prod preflight --section dns         # one section; --json for monitoring; --quiet for FAILs only
prod ingress-check                   # = prod preflight --section ingress
```

Each check reports PASS, WARN, FAIL, INFO or SKIP. The checks cover:
- **Host:** OS, Podman ≥ 4.9, binaries, lingering, the Podman socket, and the low-port sysctl
  (section 1), now and persisted.
- **Runtime:** images, health, the database, migrations current, and grants equal to the
  `schema.md` §6 matrix.
- **Exposure:** no container publishes a port, network membership as in the matrix (the ingress
  network holds only nginx and Postfix), private keys only in their own container, network flags,
  and host listeners.
- **Ingress (client addresses):**
  - `smarthost-ingress.socket` and `.service` are active, and the socket listens on exactly
    `PROXY_HTTPS_BIND` and `POSTFIX_SMTP_BIND`;
  - nginx reports, since its last start, that it inherited both sockets;
  - Postfix's port 25 is the PROXY-protocol listener on the ingress network only;
  - `TRUSTED_PROXIES` is empty;
  - **the proof:** two connections from two random loopback source addresses (`127.x.y.2` and
    `.3`) through 443 and through 25 must appear with exactly those addresses in the nginx access
    log and in the Postfix log. If both arrive as one shared address (what rootless port
    forwarding produces), this is a FAIL. Loopback sources prove the general case because nginx
    accepts on the kernel socket itself: no proxy in the path treats loopback specially.
- **TLS:** the certificate actually served on 443 and on 25 (STARTTLS).
- **DNS:**
  - A records, PTR and FCrDNS;
  - MX of the bounce domain;
  - SPF of the bounce domain and of the EHLO name;
  - DMARC of every sending domain;
  - inbound SMTP reaching this host.
- **DKIM:** for every verified domain of an active client, plus the sign-in domain: an active
  selector, a present key, the OpenDKIM table matching the database, and the DNS key matching the
  private key.
- **Postfix:**
  - hostname, no relayhost, loopback-only `mynetworks`, relay refusal;
  - bounce domain, VERP delimiter, TLS, sender ownership, the milter;
  - held or live mode, pause state;
  - a live RCPT test on 25: an outside domain is refused, the bounce domain is accepted. No mail
    is sent.
- **Delivery:** the daemon's heartbeat, its state against the configuration, fresh queue snapshots.

## 8. Activating live delivery

1. Every item of sections 4–6 is in place and `prod preflight` has no FAIL, including
   `prod ingress-check` (client addresses preserved on this host).
2. Check outbound port 25:
   `prod preflight --activation --smtp-egress-probe gmail.com`. Postfix's `posttls-finger` makes
   an EHLO and QUIT to that domain's MX; no mail is sent.
3. Activate:
   ```sh
   prod live-enable --operator admin@example.com --note "activation after preflight <date>"
   ```
   `live-enable`:
   - runs the activation preflight (any FAIL, or a WARN on a check live delivery depends on,
     aborts). It includes the host and ingress sections: an ingress that collapses clients to
     one address blocks activation;
   - writes `delivery.live_enabled` to the audit log;
   - sets `SMARTHOST_LIVE_DELIVERY_ENABLED=true` and recreates Postfix and the delivery daemon.
4. Undo: `prod live-disable --operator … --note …` returns to held mode.

## 9. Seed testing (operator-chosen addresses only)

1. Create a dedicated seed client:
   - `smarthost:client:create --status active`;
   - its sending domain with DKIM (section 6) and a verified TXT challenge;
   - `smarthost:api-key:create`. Store the key in a file readable only by you.
2. **Outbound path:** API → Go → Postfix → OpenDKIM → the recipient's MX.
   ```sh
   prod seed-test send --api-key-file ~/seed.key --from news@send.example.com \
        --to you@your-gmail.example --to you@your-outlook.example --i-own-these-addresses
   ```
   Expect every message to reach `remote_accepted`. Then open each copy and check the
   `Authentication-Results`:
   - `dkim=pass` with `d=send.example.com`;
   - `spf=pass` for `bounce.example.com`;
   - `dmarc=pass`;
   - inbox placement.
3. **Bounce path:** Internet → Postfix :25 → DSN spool → Go → message event. Send to a mailbox that
   does not exist at a domain you own (its MX must answer 5xx):
   ```sh
   prod seed-test send --api-key-file ~/seed.key --from news@send.example.com \
        --to no-such-user-1@your-own-domain.example --expect bounce --i-own-these-addresses
   ```
   Expect `hard_bounced` with a `hard_bounce` event (5.1.1), and a global `hard_bounce`
   suppression of that address. Lift it afterwards: `smarthost:suppression:lift`.
4. A complaint (ARF) cannot be produced on demand. Feedback-loop registration with mailbox
   providers points their reports at `bounce@bounce.example.com`.
5. `prod seed-test status <job-id> --api-key-file …` shows any job's messages and events.

## 10. Warm-up (traffic rollout)

The installation-wide ceiling `DELIVERY_GLOBAL_RATE_PER_MINUTE` is enforced by the delivery daemon
whatever the domains and clients. It is a hard limit, not an average. Raise the limits only on
evidence (sections 11 and 14), one stage at a time:

| Stage | `DELIVERY_GLOBAL_RATE_PER_MINUTE` | `DELIVERY_GLOBAL_CONCURRENCY` | `DELIVERY_PER_DOMAIN_CONCURRENCY` | `DELIVERY_PER_DOMAIN_RATE_PER_MINUTE` | Move on when |
|---|---|---|---|---|---|
| 0 seed | 5 | 2 | 1 | 10 | seed tests pass, headers authenticate |
| 1 | 10 | 2 | 1 | 10 | a few days without deferral spikes; hard bounces < 2 %, complaints < 0.1 % |
| 2 | 30 | 4 | 2 | 20 | same, over a week |
| 3 | 100 | 8 | 2 | 60 | same; provider dashboards (Postmaster tools, SNDS) clean |
| 4 | per volume | 10+ | 2–4 | 60+ | steady state |

To change a stage:
1. Edit the values in `infra/.env`.
2. `prod check`.
3. `prod replace delivery`. Only the delivery daemon is recreated; queued work resumes.

Watch:
- the deferral rate per client: `prod ops-status`, or the dashboard rates;
- the Postfix queue depth (deferred);
- complaints and hard bounces.

Back off (lower the values) at the first spike. A client on probation can be set `throttled`
(section 15).

## 11. Normal health checks

- Daily:
  - `prod ops-status`, or the operator Overview: backlogs, messages by status, `outcome_unknown`,
    delivery state, Postfix queue depth and snapshot age, rates per client, suppressions,
    unmatched DSNs, webhooks, workers;
  - `prod preflight --quiet`.
- Monitoring: `prod preflight --json` and `prod console smarthost:ops:status --json`.
- Weekly: certificate expiry (preflight WARN at 14 days), the backup ran (section 17), and
  `prod dkim list`.
- **nginx runs under systemd** (`smarthost-ingress.service`, which holds the HTTPS and SMTP
  sockets).
  - Restart it with `prod replace nginx` or `prod restart`, never with `podman restart` or
    `podman start`: a container started by Podman alone has no sockets and serves nothing.
  - If nginx crashes, systemd restarts it with the same sockets.
  - `prod status` shows the socket, its listen addresses and the service state.

## 12. Webhook failures

- Mail flow › Webhooks shows deliveries by state, with the last status, error and next retry, plus
  the worker heartbeats.
- A failing endpoint retries for about a day (`APP_WEBHOOK_MAX_ATTEMPTS=12`) and is then `failed`.
  Endpoints are never disabled automatically.
- Ask the client to fix the receiver. Clients reconcile by polling the API.
- Destinations must be public: an SSRF refusal names the resolved address in `last_error`.

## 13. DSN failures

- Unmatched DSNs: Mail flow › Unmatched DSNs. Inspect them; request a match only when the evidence
  identifies the message; otherwise dismiss them with a reason.
- DSNs not arriving:
  - `prod preflight --section dns --section postfix`: MX, port 25, bounce domain;
  - `prod logs postfix`;
  - the spool backlog in the delivery daemon's log and stats.
- Files the daemon cannot parse move to the spool's `failed/` directory (kept). Investigate them
  with `prod logs delivery`.

## 14. `outcome_unknown`

- A message becomes `outcome_unknown` when its Postfix queue id vanished and the Postfix log never
  showed an outcome. This needs fresh queue snapshots (the snapshot timer) and the reconciliation
  grace period.
- A rising count means one of:
  - queue snapshots are stale: the overview warns, and the preflight checks the timer;
  - log rotation outran the daemon (`POSTFIX_LOG_RETENTION_DAYS`);
  - Postfix lost its queue.
- `outcome_unknown` is never shown as delivered.

## 15. Emergency controls

**Stop all outbound mail now (pause):** the red *STOP SENDING EMAIL NOW* button on every operator
page does the same (it sets the database emergency stop the delivery daemon reads within seconds,
and the host agent holds the Postfix queue). From a terminal:
```sh
prod pause --operator admin@example.com --note "complaint spike from client X"
prod pause-status
prod resume --operator admin@example.com --note "investigated: X suspended"
```

Pause:
- sets the database emergency stop (`smarthost:delivery:emergency-stop`, audited with the operator);
- sets `defer_transports = smtp`, so mail already accepted by Postfix stays in the queue (nothing
  is lost or bounced);
- writes a durable flag, so the pause survives restarts;
- stops the delivery daemon from claiming and starting submissions within seconds.

Inbound port 25 (DSNs, complaints) keeps working. Both actions are audited (ADMIN with
`SYSTEM.DELIVERY.CONTROL`).

**One client:**
```sh
prod client-status <client-id> suspended --operator admin@example.com --note "complaint spike"   # the per-client kill switch
prod client-status <client-id> throttled --operator admin@example.com --note "bounce rate 4 %"   # slower, still working
prod client-status <client-id> active --operator admin@example.com --note "list cleaned"
```

Every change needs the acting operator (with `PLATFORM.CLIENT.RESTRICT`, or
`PLATFORM.CLIENT.APPROVE` to reactivate a suspended client) and a reason, which the audit log
keeps. Suspended: new work is refused (403), the delivery daemon loses the lease of the client's
running send jobs at the next renewal and the validator stops claiming its jobs. Throttled: its
API rate is capped (`APP_THROTTLED_CLIENT_API_RATE_PER_MINUTE`) and its message starts are paced
at `DELIVERY_THROTTLED_CLIENT_RATE_PER_MINUTE`. The same controls are on Clients › Clients ›
*client* › Lifecycle. A suspension does not recall mail Postfix has already accepted.

| Where the mail is | What stops it |
|---|---|
| Not yet submitted (send job queued or processing) | suspend the client, cancel the job, or pause |
| Accepted by Postfix, in its queue | pause: it stays queued. To remove it: `postsuper -d` after inspection |
| Delivered to the remote MX | nothing: it is out |

**Observe queued work:**
- `prod queue` (`postqueue -p`) and `prod pause-status` (queue depth);
- the operator Overview (send backlog);
- `prod ops-status`.

## 16. Retention

Empty `APP_RETENTION_*` means **no automatic deletion**; see *Retention settings in production* in
the environment contract. Before long-term operation, record a decision for each category with
your compliance owner. Configuring a period today records the policy only: no deletion command
exists yet for the long-term categories.

## 17. Backups

**Scheduled (specification 2.11):** `<instance>-backup.timer` runs `prod backup --scheduled` at
`BACKUP_SCHEDULE`: a backup in `BACKUP_DIR`, encrypted when `BACKUP_ENCRYPTION_PASSPHRASE_FILE` is
set (one `.tar.enc` file, AES-256), the newest `BACKUP_KEEP` kept, copied with rsync to
`BACKUP_OFFHOST_TARGET` (SSH key `BACKUP_OFFHOST_SSH_KEY`). `prod backup-status` shows the last
backup, the off-host copy and the last restore rehearsal; `prod restore-rehearsal [backup]`
restores the newest backup into a temporary database, verifies it and drops it. `prod restore`
accepts the encrypted file directly. Manual backups:

```sh
prod backup                                   # infra/.generated/backups/<UTC timestamp>/ (0700)
prod backup /srv/backups/catto-$(date +%F) --with-queue   # include the Postfix queue and DSN spool (pause first)
```

| Contents | Authoritative? |
|---|---|
| `db.dump` (pg_dump, custom format) | yes |
| `opendkim-keys.tar`, `opendkim-tables.tar` | yes |
| `env` (all secrets, the keyring) | yes |
| TLS secrets | exported when Podman supports `secret inspect --showsecret`; otherwise keep the CA/ACME source files |
| `MANIFEST` | migrations, image tag, checksums |

The Postfix queue and the DSN spool are transient and are not included by default. Mail in the
queue at the moment of a disaster is lost unless backed up with `--with-queue`; its messages show
`outcome_unknown` after restore.

Encrypt every backup (it contains every secret) and copy it off the host. Schedule it with a
systemd user timer or cron.

## 18. Upgrades

```sh
git fetch && git checkout v<new>
prod upgrade <new-tag>          # runtime preflight, backup, build <new-tag>, recreate (migrations + grants at start), runtime preflight
prod preflight                  # full check
```

- Released migrations are immutable; schema changes come as new migrations.
- Read the CHANGELOG for new variables. `prod check` names any missing ones; add them to
  `infra/.env` first.

**Rollback:**
- `prod rollback <previous-tag> <backup-dir-from-the-upgrade>` restores the previous images and the
  database taken before the upgrade.
- Mail state recorded after the backup is lost, so roll back promptly, and pause first.

## 19. Recovery

**To a new host, or as a rehearsal:**
1. Prepare the host as in section 1, at the same release.
2. Copy the backup's `env` to `infra/.env`.
3. `prod build`, `prod tls set …`, `prod install`.
4. `prod restore <backup-dir> --yes`.
5. Point DNS and the PTR at the new address if it changed, and update `SMARTHOST_PUBLIC_IPV4`.
6. `prod preflight`, then `live-enable` again.

**Rehearse without production traffic:** `infra/bin/smarthostctl test phase8-rehearsal` deploys
the production topology locally with no Internet egress and runs backup and restore against it.

## 20. Client accounts (Phase 9)

Procedures for taking on a client, its limits, usage and billing statements, and reputation
alerts: [onboarding.md](onboarding.md). In short:

```sh
prod console smarthost:client:create --company "Example Ltd" --contact-email ops@example.net \
  --abuse-contact-email abuse@example.net --billing-contact-email billing@example.net   # pending_approval
prod console smarthost:client:limits <client-id> --set send_recipients_per_day=5000 \
  --operator admin@example.com --note "plan agreed"
prod client-status <client-id> active --operator admin@example.com --note "reviewed: policy accepted, domain verified"
prod console smarthost:reputation evaluate      # also every 15 minutes by <instance>-reputation-evaluate.timer
prod console smarthost:usage:reconcile --client <client-id> --period previous_month
prod console smarthost:billing:statement prepare --client <client-id> --period previous_month --operator admin@example.com
```

Alerts (Clients › Abuse & reputation) never act on a client: decide, act through the lifecycle above, and
acknowledge the alert with a note. A failing preflight `runtime / reputation evaluation` check
means the timer has not run for an hour: `prod machine systemctl list-timers`.

## 21. Operator self-service (specification 2.11)

- **First sign-in / recovery:** `prod admin-link` prints a single-use ADMIN link (15 minutes).
- **Host agent:** `<instance>-host-agent.service` (`prod agent run`). It records the host-side
  checks every minute (the full preflight hourly) and carries out dashboard requests; see them
  under Mail flow › Delivery › Host requests. `prod agent once --requested` runs everything now;
  `prod agent facts` prints what it reports. Its log: `prod logs agent`.
- **Diagnostics:** System › Diagnostics (and its history); System › System setup for the guided checks.
- **Logs:** `prod logs app|webhook|validator|delivery|postfix|opendkim|nginx|postgres|agent [lines|-f]`.
- **Address batches and re-permission:** Clients › Address batches; Help › Uploading lists and
  Re-permission.

## 22. Still to do outside the repository

- [ ] The VPS (section 1), outbound port 25 unblocked, and the PTR of the sending IP.
- [ ] DNS records of section 5 for the public hostname, the mail hostname and the bounce domain.
- [ ] TLS certificates and their automated renewal.
- [ ] Production DKIM keys and DNS for each sending domain and for the `APP_MAIL_FROM` domain.
- [ ] DMARC records with a reporting mailbox.
- [ ] `net.ipv4.ip_unprivileged_port_start=25` persisted on the VPS (section 1), and
      `prod preflight --section host` without FAIL.
- [ ] `prod ingress-check` passes on the VPS (nginx/Symfony and Postfix see each client's own
      address).
- [ ] The first `prod preflight --activation --smtp-egress-probe …` without FAIL, then `live-enable`.
- [ ] Seed tests (delivered and bounce) with addresses you own.
- [ ] Feedback-loop registrations, and Postmaster tools / SNDS.
- [ ] Off-host encrypted backups on a schedule; one restore rehearsal.
- [ ] Retention decisions per category.
