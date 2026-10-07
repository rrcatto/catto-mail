# Smarthost Project Specification

**Specification version:** 2.10. This is a documentation revision, not a software release version.
**Revision date:** 6 October 2026 (original: 8 September 2026)
**Status:** Canonical architecture and development plan

The canonical, machine-readable specification is `docs/20260908-1644-smarthost-llm-spec.yaml`.
This document explains the same architecture for human readers. If the two ever disagree, the
YAML is authoritative. Exact enum values, API shapes, schema, environment variables and Postfix
mechanics live in the normative contract files that the YAML lists under `normative_contracts`.
`docs/architecture/open-decisions.md` is only a decision log.

---

## 1. Purpose

Smarthost is a self-hosted email infrastructure platform. It starts single-tenant in operation,
and its data model and security boundaries are multi-tenant, so it can later become a commercial
service for third-party clients.

It gives an organisation direct control over two things that are usually bought as expensive
external services:

1. **Email validation and list hygiene**: determine what can reasonably be learned about an address without sending mail, while explicitly preserving uncertainty.
2. **Tracked SMTP delivery**: send mail through Postfix, record transport outcomes, process bounces, and record engagement events such as opens and clicks.

Smarthost is not a mailing-list application, and it is not a template or mail-merge engine. Those
responsibilities belong to its client applications.

---

# 2. Smarthost and Its Client Applications

Smarthost is infrastructure. Applications that manage mailing lists and campaigns are separate
**client applications** that use it through its public API.

```text
Client application (e.g. a mailing-list / campaign application)
        │
        │ HTTPS Smarthost API (fully rendered messages)
        ▼
    Smarthost
        │
        ▼
     Postfix ──► OpenDKIM (signing)
        │
        ▼
     Internet
```

## 2.1 Client applications

A client application owns mailing-list business logic:

- mailing lists;
- subscribers;
- list memberships;
- subscriber fields;
- consent history;
- confirmation state;
- unsubscribe state and the unsubscribe endpoint (ordinary list, newsletter, campaign and client unsubscribes stay here);
- campaign creation;
- campaign templates, merge fields and **rendering**;
- segmentation;
- mapping subscribers and campaigns to Smarthost jobs/messages.

The client produces the **fully rendered, recipient-specific** subject, HTML body and plain-text
body for every recipient, and the opaque per-recipient unsubscribe URL. Smarthost receives
finished messages and never merges, renders or substitutes variables.

A trusted client application may also report a **recipient global opt-out** to Smarthost: the
recipient explicitly asked not to receive email from any source using the Smarthost installation
(§13.4). That narrow instruction becomes Smarthost state; an ordinary unsubscribe never does.

Every client application, including the first one, **communicates with Smarthost through the same
public API that a future paying customer would use**. No client ever connects directly to the
Smarthost PostgreSQL database.

## 2.2 Smarthost

Smarthost owns infrastructure concerns:

- API clients, credentials, dashboard users and client memberships;
- sending domains and their verification;
- validation jobs and validation evidence;
- SMTP send jobs and their staged recipient input;
- individual recipient messages;
- Postfix queue identifiers;
- transport events;
- bounces and deferrals;
- complaints;
- transport suppressions;
- open and click tracking;
- operational reputation metrics;
- usage metering;
- future SaaS account controls.

This separation prevents two systems from independently deciding whether somebody is a member of a mailing list.

---

# 3. Technology Standard

The PHP applications should standardise on **Symfony**.

For Smarthost, Symfony provides:

- Doctrine ORM and Doctrine Migrations;
- Symfony Security;
- API-oriented controller infrastructure;
- rate limiting;
- Symfony console commands for long-running workers and scheduled tasks;
- Symfony Mailer for Smarthost's own low-volume transactional notifications;
- Twig;
- Symfony UX;
- LiveComponent;
- Stimulus;
- PHPUnit integration.

The Smarthost remains deliberately polyglot because the non-PHP components have different jobs:

| Component | Technology | Purpose |
|---|---|---|
| Reverse proxy | nginx | TLS termination and public HTTPS routing to PHP-FPM via FastCGI |
| Web application/API | PHP + Symfony on PHP-FPM | API, authentication, dashboards, tracking endpoints, schema ownership |
| Webhook worker | Symfony console worker (same image) | The only component that delivers client webhooks |
| Validation worker | Python + asyncio | DNS/MX/SMTP validation and classification |
| Delivery/control daemon | Go | Message creation, MIME assembly, Postfix submission, event processing, VERP, DSNs, reconciliation, pacing |
| SMTP engine | Postfix | Queueing, retry, SMTP transport, TLS |
| DKIM signing | OpenDKIM (milter) | Outbound DKIM signing for Postfix |
| Durable state | PostgreSQL 16.x | Shared internal Smarthost state |
| Development mail capture | Mailpit | Prevent real mail leaving the development machine |
| Deterministic SMTP simulator | fake SMTP service | Reproduce specific SMTP responses for tests |

There is no separate PHP HTTP application server. nginx talks to PHP-FPM directly over FastCGI on the internal Podman network.

---

# 4. Core Architectural Rules

The following rules are architectural invariants.

1. **Postfix remains the SMTP transport.** We will not write a replacement SMTP server.
2. **Symfony owns the database schema.** Doctrine entities and migrations are authoritative for tables, indexes, keys and constraints.
3. **Python and Go may read/write approved tables but may not alter schema.** Their database roles have no schema-altering privileges.
4. **Client applications never access the Smarthost database.** They use HTTPS API calls and signed webhooks.
5. **Tracked bulk/campaign sending is executed by Go**, not directly by Symfony Mailer.
6. **Smarthost performs no mail merge or template rendering.** It receives fully rendered recipient-specific content.
7. **Python does not expose a public API.** It is a background worker.
8. **Go does not expose a public API.** It is a background daemon.
9. **PostgreSQL, PHP-FPM and OpenDKIM are not publicly exposed.**
10. **Only the Symfony webhook worker delivers webhooks.** Other components record webhook events in a transactional outbox.
11. **All normal development mail is captured locally.** Automated tests must never send Internet email.
12. **Podman is the container runtime.** Docker is not the project standard.
13. **Rootless Podman is required.**
14. **No Kubernetes.** The deployment target is a small number of containers on one VPS initially.
15. **No additional queue infrastructure initially.** PostgreSQL job tables and LISTEN/NOTIFY are sufficient until actual load demonstrates otherwise.
16. **Mailing-list consent and unsubscribe belong to the client application.** Transport suppression belongs to Smarthost.
17. **SMTP acceptance is not described as proof that a person received or read a message**, and an unknown outcome is never described as success.
18. **The only milter is OpenDKIM.** It is an explicit, justified exception to the original no-milter preference, because DKIM signing is a concrete requirement.

---

# 5. Development and Production Container Topology

Client applications and unrelated projects may share the same Podman machine during development, but they must not be collapsed into one application environment.

```text
Podman machine
│
├── Smarthost pod "smarthost" on the internal network
│   ├── nginx reverse proxy          [only public HTTPS entry; published by the pod]
│   ├── Symfony app (PHP-FPM)        [not published; called by nginx via FastCGI]
│   ├── Symfony webhook worker       [same image, separate unit]
│   ├── Python validator
│   ├── Go delivery daemon
│   ├── Postfix
│   ├── OpenDKIM                     [milter; not published; called by Postfix only]
│   ├── PostgreSQL
│   ├── Mailpit                      [development only]
│   └── fake SMTP server             [development/test]
│
└── Client application and other projects
    └── their own networks and databases
```

In development all Smarthost containers run in a single Podman **pod** named `smarthost`:

- The pod alone joins the internal network, carries the service aliases (`postgres`,
  `symfony-app`, `postfix`, `opendkim`, `mailpit`, `fake-smtp`) and publishes the only host ports
  (nginx HTTPS and the Mailpit UI).
- Members share one network namespace, so loopback is shared. No service treats `127.0.0.1` as a
  trusted client: Postfix port 25 never relays, whatever the client address.

Smarthost and its client applications should remain independently deployable. In production
Smarthost runs on its own containerised VPS.

## Production topology (specification 2.9)

The members of a pod share one network namespace, so a pod cannot give its components different
connectivity. Production therefore runs the same images as eight **standalone** persistent
rootless containers, `<instance>-<service>` (default instance `smarthost`). They are rendered from
`infra/podman/smarthost-production.sh.in` and operated only through `smarthostctl prod`. The
development pod is unchanged.

```text
production host (Ubuntu 24.04, rootless Podman, lingering service user)
│
├── <instance>-internal  (internal: no route out; fixed addresses .10-.17)
│   postgres · opendkim · nginx · delivery              ← internal only (nginx also on ingress)
│   postfix · symfony-app · webhook-worker · validator  ← also on egress
│
├── <instance>-ingress   (internal; nginx and postfix only)
│   nginx → postfix-ingress:25 (SMTP with the PROXY protocol)
│
├── <instance>-egress    (routed through the host)
│   postfix        → SMTP 25 to recipient MX hosts
│   validator      → DNS, SMTP 25 probes (only when enabled)
│   webhook-worker → HTTPS to public client endpoints
│   symfony-app    → DNS (sending-domain verification)
│
└── no published ports: <instance>-ingress.socket (systemd) binds 0.0.0.0:443 and 0.0.0.0:25
    and hands both sockets to nginx (socket activation, client addresses preserved)
```

Every service also gets `/etc/hosts` entries for the others, so name resolution does not depend
on the aardvark-dns version.

**Host firewall.** The generated nftables ruleset admits SSH, 25 and 443. It limits the service
user's outbound traffic, which carries all rootless egress, to DNS, 25, 80 and 443.

**Lifecycle.** The same persistent-object rules as D-35 apply, and named volumes are never
deleted:

- stop, start and restart keep every container;
- recreate, upgrade and rollback replace them;
- `replace <service>` recreates single containers.

**Ingress: client addresses are preserved.** Rate limiting, diagnostics and logs need each
client's real address. Rootless port forwarding replaces it with one internal address (measured),
so production publishes no port:
- The service user's systemd binds 443 and 25 on the host (`<instance>-ingress.socket`).
- `<instance>-ingress.service` starts the persistent nginx container with both sockets.
- nginx accepts every connection itself. HTTPS reaches Symfony with the client as `REMOTE_ADDR`
  (`TRUSTED_PROXIES` is empty in production, so forwarded headers are ignored).
- SMTP is passed to Postfix over the `<instance>-ingress` network with the PROXY protocol. Only
  nginx can reach that listener, and Postfix logs and checks the client address.
- `smarthostctl prod ingress-check` proves it on the host with two loopback source addresses. It
  is part of the activation preflight.

**Host prerequisite.** The unprivileged service user binds 25 and 443 because the host sets
`net.ipv4.ip_unprivileged_port_start=25` (persisted in `/etc/sysctl.d`). The setting is
system-wide (any local user may bind 25–1023; ports below 25 stay privileged), which is accepted
for a dedicated host. The preflight's `host` section checks it, and every `prod` command that
creates or starts containers checks it first.

**Launch decisions:**
- Postfix sends over IPv4 only; IPv6 would be a separate sending identity (forward DNS, PTR, SPF,
  reputation monitoring).
- Validator SMTP probing is off; enabling it is an explicit operator decision (reputation).
- Retention periods are unset, so nothing is deleted automatically.

The network matrix, the live-delivery states and where each kind of state lives are in
`docs/production/README.md`. The procedures are in `docs/production/runbook.md`.

## Pod lifecycle and process management

The Smarthost pod and its service containers are **persistent rootless Podman objects** (D-35).
They are created once and then started and stopped like any pod managed with Podman or Podman
Desktop:

- **create:** create the pod and its containers from the current images and configuration;
- **start:** start the existing objects; the database role bootstrap, migrations and grants run as
  ordered one-off steps;
- **stop:** stop the pod; the pod and its containers stay, shown as stopped/exited;
- **restart:** stop and start the same objects;
- **recreate:** deliberately replace the pod and containers after image or definition changes;
  the named volumes are kept;
- **destroy volumes:** the only operation that deletes persistent data, explicit and separate.

A systemd user service starts the existing pod when the Podman machine boots and stops it at
shutdown. It never removes the pod or its containers, so stopping or starting the pod directly
from Podman or Podman Desktop is equally safe. (Until D-35 the services ran as Quadlet units,
which delete their containers and pod whenever they stop.)

The environment should support:

- automatic restart on failure (Podman restart policy);
- controlled startup/shutdown;
- `systemctl --user status smarthost.service` and `podman logs` for the services;
- persistent Postfix queue storage;
- persistent PostgreSQL storage;
- a shared Postfix observability volume (log and queue snapshots) and a shared DSN spool volume;
- mounted configuration/secrets, including DKIM keys visible to OpenDKIM only;
- systemd timers for Postfix log rotation, queue snapshots and Symfony scheduled commands;
- the same service topology in development and production.

The differences between development and production should be configuration, not architecture. These are:

- secrets and domain names;
- certificates and DKIM keys;
- live-relay enablement versus Mailpit;
- resource limits.

Since specification 2.9 two more differences are allowed:

- the deployment topology: one pod versus standalone containers on an internal and an egress
  network;
- the production held state before live activation.

## Database roles

PostgreSQL roles are infrastructure, not Doctrine schema. An explicit bootstrap under `infra/` creates them with an administrative connection and applies table grants after each migration run. The separate least-privilege identities are:

| Role | Used by |
|---|---|
| `smarthost_owner` | Doctrine migrations only |
| `smarthost_app` | Symfony web and scheduled commands |
| `smarthost_webhook` | Symfony webhook worker |
| `smarthost_validator` | Python validator |
| `smarthost_delivery` | Go delivery daemon |

---

# 6. Email Validation Design

The validator is deliberately evidence-based rather than a simplistic "valid/invalid" checker.

A twenty-year-old list will contain several different classes of problem, and no single regular expression or SMTP response can solve all of them.

## 6.1 Public Job Size and Input

A public validation job is capped at **10,000 addresses**.

Each submitted address may carry an optional, opaque `external_address_reference`. This lets the client map results back to its own source records without relying solely on the email address. It is separate from the job's own `external_reference`.

Internally, a worker processes the job in smaller chunks, for example 100–500 rows at a time, with strict global and per-domain concurrency limits.

A job containing 4,000 Gmail addresses must not result in 4,000 simultaneous connections to Google's mail servers.

Validation jobs move through `queued → processing → completed`, or end in `failed` or `cancelled`.

## 6.2 Validation Pipeline

### Stage 1: Preserve and normalise

Retain the exact original input.

Safe normalisation may include:

- surrounding whitespace removal;
- normalising domain case;
- canonical internal storage of the parsed domain.

The validator must never silently change a questionable address.

### Stage 2: Syntax validation

Perform standards-aware parsing without making a network request.

Malformed addresses are rejected at this inexpensive stage.

### Stage 3: Typo analysis

This specifically addresses misnamed addresses such as:

```text
alex@gmal.com
john@gmail.con
mary@hotnail.com
```

The system may suggest a correction but may never silently apply one.

A result might therefore say:

```text
Original:    alex@gmal.com
Suggestion:  alex@gmail.com
Reason:      provider_domain_edit_distance
Confidence:  high
```

Reason codes distinguish:

- a known-provider edit-distance match;
- a transposition;
- a likely TLD error;
- a known, maintained legacy alias;
- another recognised domain typo rule.

Confidence is categorical (`low`, `medium`, `high`) and is never presented as a fabricated probability.

### Stage 4: Domain and DNS state

The validator must distinguish more than "has MX / no MX".

Important states include:

- valid MX records;
- Null MX, explicitly declaring that the domain does not accept mail;
- no MX but valid SMTP address-record fallback where standards permit it;
- NXDOMAIN;
- temporary DNS failure;
- malformed or unusable DNS response.

Temporary DNS failures are not permanent invalid verdicts.

### Stage 5: Disposable-domain flag

Check the domain against a maintained disposable-domain dataset.

This should normally be a risk flag rather than an irreversible deletion rule.

### Stage 6: Role-account flag

Addresses such as the following should be identified:

- `info@`;
- `sales@`;
- `admin@`;
- `support@`;
- `postmaster@`;
- `abuse@`.

Role accounts are not automatically invalid. They are simply different-risk addresses.

### Stage 7: SMTP RCPT probing

Where appropriate, the Python worker connects to the destination mail system and performs an SMTP conversation up to `RCPT TO`.

It must **never send `DATA`**.

The purpose is to discover whether the remote system rejects the mailbox at SMTP time.

The worker must handle:

- `5xx` permanent rejects;
- `4xx` temporary rejects;
- greylisting;
- timeouts;
- connection refusal;
- providers that accept every recipient;
- providers that deliberately obstruct validation probing.

Per-domain and per-MX rate limits are mandatory.

### Stage 8: Evidence-based classification

A single mutually exclusive enum is insufficient because an address can simultaneously be syntactically valid, SMTP-accepted, a role account and a disposable account.

The result therefore contains independent evidence fields:

```text
external_address_reference
syntax_status
domain_status
smtp_status
is_role
is_disposable
is_catch_all_or_accept_all
is_domain_typo_suspected
suggested_address
suggestion_reason_code
suggestion_confidence
overall_classification
confidence
diagnostic_code
diagnostic_text
```

Overall classifications are:

- `deliverable`;
- `probably_deliverable`;
- `undeliverable`;
- `temporarily_unverifiable`;
- `unknown`;
- `risky`.

The validator should be conservative. "Unknown" is a valid technical result.

## 6.3 Work claiming

All asynchronous work in Smarthost is claimed from PostgreSQL using transactional claiming (`FOR UPDATE SKIP LOCKED` where appropriate), with these lease fields:

- `claimed_by`;
- `lease_expires_at`;
- `attempt_count`;
- `next_attempt_at`;
- `last_error`.

The rules are:

- A worker on long-running work renews its lease before it expires. If renewal fails, it stops processing that item.
- Every write a worker makes is conditional on it still holding the lease, so two workers never process the same active lease.
- If a worker dies, its lease expires and another worker may safely reclaim the work.

The same rules apply to validation addresses (Python), send jobs (Go) and webhook deliveries (the Symfony worker).

The validator claims only addresses of active or throttled clients, and renewals and result writes re-check that status, so a suspension stops work in progress without publishing results (D-31). It meters usage itself: one `validation_address` unit for each address that actually reaches its final state, written in the same transaction as the result, so retries, reclaims and crashes never double-count and unprocessed addresses are never metered (D-33).

---

# 7. List Hygiene Principles

Validation results feed decisions that belong to the client application. Smarthost's role is to
provide evidence, never to make consent or membership decisions. The following principles apply
to any client workflow built on Smarthost:

- addresses are never silently rewritten, and typo corrections are suggestions only;
- the provenance of every submitted address remains auditable on the client side;
- SMTP acceptance is never treated as proof of consent, and consent is never inferred from mere
  possession of an address;
- large address lists are validated in batches (at most 10,000 addresses per job) and are never
  bulk-sent simply because a validator returned a positive result.

## 7.1 Live-sending compliance gate

Technical validation is not proof of consent. Consent-dependent campaign mail is not sent to the
public Internet until the operator's legal/compliance specification for that sending has been
reviewed and approved. Technical development, validation and local or Mailpit testing are not
blocked by this gate.

---

# 8. Sending Architecture

## 8.1 Authoritative rule

**All tracked bulk/campaign messages are executed by the Go delivery service.**

```text
Client application (renders every recipient's message)
   │
   ▼
Smarthost Symfony API (collect recipients in batches, then submit)
   │
   ▼
PostgreSQL send_job + send_job_recipients
   │
   ▼
Go delivery daemon (creates messages, instruments, submits)
   │
   ▼
Postfix ──► OpenDKIM signs
   │
   ▼
Internet
```

Symfony Mailer may still be used for low-volume transactional messages belonging to Smarthost itself, such as account notifications. It must not become an alternate campaign-delivery path.

## 8.2 Fully rendered recipient messages

Smarthost does **not** perform mail merge, template rendering, variable substitution or template management, and it stores no merge-data contract. For every recipient, the client application submits the finished values:

- external recipient reference;
- recipient email address;
- rendered subject;
- rendered HTML body and/or rendered plain-text body;
- the unsubscribe URL, for subscription messages.

Smarthost must not become a second campaign or template engine.

## 8.3 Subscription versus transactional messages

Every send job declares a message class.

- **`subscription`** covers newsletters and marketing mail.
  - The job must carry a `list_id`.
  - Every recipient must carry an opaque HTTPS `unsubscribe_url` generated by the client application.
  - Go writes these headers:

    ```text
    List-Unsubscribe: <https://…>
    List-Unsubscribe-Post: List-Unsubscribe=One-Click
    List-Id: <list_id>
    ```

  This is RFC 8058 one-click unsubscribe. The one-click POST goes straight to the client application, which owns the unsubscribe endpoint and state. Smarthost keeps no unsubscribe database and records no unsubscribe events. The visible unsubscribe link in the body is client content.
- **`transactional`** covers individually triggered mail. `unsubscribe_url` and `list_id` are not accepted in v1.

## 8.4 Batched recipient ingestion

A 10,000-recipient job with fully rendered content cannot sensibly fit into one request. The normal request limit stays at 10 MiB and is not raised for this. Instead, recipients are uploaded in stages:

1. `POST /v1/send-jobs` creates the job in **`collecting`** state with job-level metadata only:
   - external reference;
   - message class;
   - list id;
   - sender identity;
   - optional `Reply-To`;
   - tracking options.
2. `POST /v1/send-jobs/{id}/recipients` adds a batch of **at most 500** fully rendered recipients.
   - Each batch requires an idempotency key.
   - A batch is accepted or rejected as a whole.
   - The running total may not exceed the configured job limit (initially 10,000).
   - Duplicate-address rules apply across all batches.
   - Recipients can only be added while the job is `collecting`.
3. `POST /v1/send-jobs/{id}/submit` atomically seals the job. It rejects:
   - an empty job;
   - a job over the ceiling;
   - missing class metadata;
   - an unverified (or, in production, DKIM-inactive) sending domain.

   On success the staged recipient set becomes immutable and the job becomes `queued`.

Compression is not required for correctness and may be added later as a transport optimisation.

**Duplicate recipients.** Recipients are compared after address normalisation: surrounding whitespace is trimmed, the domain is lower-cased, and the local-part case is preserved. `John@example.com` and `john@example.com` are distinct addresses, but `" John@Example.COM"` duplicates `"John@example.com"`. An exact duplicate is rejected, so an accidental duplicate cannot cause two deliveries.

## 8.5 Send-job lifecycle

| State | Meaning |
|---|---|
| `collecting` | Accepting recipient batches; the only editable state |
| `queued` | Sealed; waiting for Go |
| `processing` | Go is creating recipient messages and submitting them to Postfix |
| `dispatched` | Recipient creation/submission work is finished. Each recipient was accepted into Postfix, deliberately suppressed, or hit an immediate submission failure. Recorded as `dispatch_completed_at`. |
| `completed` | No automated SMTP transport resolution remains outstanding. Every recipient has a terminal knowledge state, including `outcome_unknown`. Recorded as `completed_at`. This can be days after dispatch. |
| `failed` | The job as a whole could not be executed |
| `cancelled` | Operator emergency stop before dispatch finished, or an abandoned collecting job |

Operator throttling and suspension act on the **client** (its status), and workers stop claiming or continuing a suspended client's work. Only `active` and `throttled` clients may create or add work: for `pending_approval` and `suspended` clients the four work-creating operations (`POST /v1/validation-jobs`, `POST /v1/send-jobs`, recipient batches and submit) answer 403, while reads keep working. Keys of a `closed` client no longer authenticate (D-31). Later opens, clicks, complaints or recovered transport information may update messages and statistics, but they never reopen a completed job.

## 8.6 Per-recipient message generation

Symfony persists the submitted recipients in **`send_job_recipients`**, which is staged input and holds no transport state. When Go processes the job, it creates one **`messages`** row per recipient and allocates:

- a Smarthost message ID;
- a unique VERP token and envelope return path;
- an opaque tracking token when tracking is enabled;
- the Postfix queue ID after submission.

This allows transport events to be mapped back to a specific recipient without guessing from bounce text. If an active transport suppression matches, the recipient is recorded as `suppressed` and is not submitted.

## 8.7 Message headers

Arbitrary caller-supplied MIME headers are not accepted in v1.

| Header | Source |
|---|---|
| `From` | The approved sender identity on a verified sending domain |
| `Reply-To` | Optional, job-level |
| `To` | The recipient |
| `Subject` | The fully rendered recipient subject |
| `Message-ID`, `Date` and transport headers | Generated by the delivery system |
| `List-Unsubscribe`, `List-Unsubscribe-Post`, `List-Id` | Subscription messages only (§8.3) |

Any additional header requires an explicit future API change.

## 8.8 Delivery-time tracking instrumentation

Tracking is **disabled unless explicitly enabled** for the send job. When it is enabled, Go instruments the rendered content after receiving it:

- **Open tracking** inserts a pixel into HTML messages only.
- **Click tracking** rewrites eligible absolute HTTP/HTTPS links in the HTML part through the click tracker, and stores each original target in a server-side link map.

It never rewrites plain-text URLs, `mailto:` URLs, fragment-only URLs, non-HTTP/HTTPS URLs, or unsubscribe URLs.

## 8.9 Sending domains and DKIM

A client may own several sending domains. To verify a domain:

1. Smarthost generates a cryptographically random token.
2. The client publishes a TXT record at `_smarthost-verification.<domain>` with the value `smarthost-verification=<token>`.
3. Symfony checks the DNS TXT value. The domain becomes `verified`, and the verification time is recorded, only after the challenge matches.

The token is published in DNS, so it is not an authentication secret and is not hashed.

Outbound mail is DKIM-signed by **OpenDKIM**, an internal container that Postfix calls through the Milter protocol on the private network:

- DKIM private keys are available to OpenDKIM only. Go never holds DKIM keys or signs mail.
- Signing is based on the verified sending domain and its DKIM selector and status, which the sending-domain model carries.
- The milter applies only to outbound submission. Inbound DSN traffic is never treated as client mail to be signed.
- If OpenDKIM fails, Postfix temporarily rejects the submission and Go retries. A tracked message is never sent unsigned because of a milter failure.
- Live sending rejects an unverified sending domain (and, in production, one without active DKIM), except in explicitly controlled local/test modes.

## 8.10 DSN request behaviour

Where supported and appropriate:

- request `FAILURE,DELAY` reports;
- request headers rather than full-message return content;
- retain original-recipient information as a fallback mapping mechanism;
- set the envelope ID to the Smarthost message ID.

## 8.11 Pacing

The Go daemon imposes configurable sending discipline in addition to Postfix's own queue/retry behaviour:

- global concurrency;
- per-destination-domain concurrency;
- per-domain sending rate;
- provider-specific temporary backoff;
- reaction to repeated deferrals.

## 8.12 Transient rendered content

Rendered recipient content is transient transport material, not Smarthost's long-term campaign archive.

- As soon as Postfix has accepted a message and its queue ID is recorded, the recipient's rendered subject and bodies are purged in the same transaction.
- Smarthost keeps identifiers, external references, envelope and transport metadata, content byte counts, content hashes and the event history.
- Content of abandoned, cancelled, suppressed or permanently failed recipients is purged after a configurable period, 7 days by default. Abandoned collecting jobs are cancelled at the same time.

---

# 9. Postfix and Bounce Handling

Postfix remains responsible for SMTP transport, TLS negotiation, queueing and retries.

## 9.1 Production port 25

The production design must include inbound SMTP for the bounce domain.

If Smarthost sends with an envelope sender such as:

```text
bounce+TOKEN@bounce.example.com
```

then a mail server must receive DSNs for `bounce.example.com`.

Production Postfix therefore receives mail on TCP port 25 for that domain. It accepts only VERP, postmaster and feedback-loop recipients.

## 9.2 Production port 587

Port 587 is used for authenticated submission. Go submits there, and the OpenDKIM milter applies there.

## 9.3 Go ↔ Postfix integration channels

Go never runs its own inbound SMTP listener, never reads the host systemd journal, and never implements a milter. There are separate channels:

1. **Submission.** Go submits each message over SMTP on the private network, one recipient per transaction, and captures the Postfix queue ID from the acceptance reply.
2. **Transport events.** Postfix writes its log into a shared, container-owned observability volume that Go mounts read-only.
   - Go stores its read position in PostgreSQL, identified by **log generation and position**, because a raw byte offset is not enough across rotation.
   - The position is updated in the same transaction as the events it produces.
3. **Queue snapshots.** The Postfix environment periodically writes atomic JSON queue snapshots (`postqueue -j`) into the same volume (§9.5).
4. **Inbound DSNs.** Postfix delivers bounce-domain mail into a dedicated shared Maildir spool. Go claims each file atomically by renaming it.

Every transport event records its **source** and a **stable source event key**, for example the log generation and record position, or the Maildir file's unique name. Replaying a log or re-processing a DSN after a restart never duplicates events. De-duplication is never based on event content alone, because two legitimate SMTP events can carry identical text.

File ownership, atomic claiming, retention, log rotation and the remaining image-level verification tasks are documented in `docs/architecture/postfix-integration.md`.

## 9.4 Unmatched DSNs

A DSN that cannot be associated with any message cannot be a normal message event. It is stored in an operator-facing **unmatched DSN** record with enough raw and parsed information to investigate it. To resolve one:

1. The operator identifies the candidate Smarthost message in the dashboard.
2. The resolution is queued for Go.
3. Go interprets the DSN and creates the appropriate message transport event.
4. The original unmatched record is kept, with who resolved it, when, and which message it matched.

Irrelevant DSNs, such as backscatter, can be dismissed with a written reason. If Go cannot interpret
the DSN for the requested message (it reports no failure for it), the record returns to open with
the reason, and no event is created. Operators work through the operator dashboard (Unmatched
DSNs: list, detail with the parsed evidence and candidate messages, match request, dismissal with a
written reason) or the audited console commands (`smarthost:dsn:list`, `smarthost:dsn:show`,
`smarthost:dsn:match`, `smarthost:dsn:dismiss`). Both use the same application service, record the
operator's identity and only record the request; Go applies a match.

## 9.4.1 Inbound DSN and complaint processing

Go parses each spool file as an RFC 3464 delivery status notification (including the RFC 6533
international form), an RFC 5965 ARF feedback report, or a non-standard bounce. The content is
untrusted data: it is never executed, rendered or followed, and sizes and MIME structure are
bounded. A malformed file never fails processing; it becomes an unmatched DSN.

Correlation uses only Smarthost-issued identifiers, strongest first:

1. the VERP token of the envelope recipient (the `Delivered-To` header the receiving Postfix adds);
2. `Original-Envelope-Id` (the Smarthost message id sent as ENVID);
3. `X-Smarthost-Message-ID`, or a Smarthost `Message-ID`, in the returned headers;
4. the Postfix queue id (`X-Postfix-Queue-ID`).

An identifier that names no message is not evidence; identifiers naming different messages are a
conflict. Smarthost never guesses: an uncorrelated report becomes an unmatched DSN. The recipient
address is never enough, not even with a matching returned `From`/sender (D-36): suppressions are
global, so a forged DSN must not be able to suppress an address. Recent messages to the reported
recipient are stored with the unmatched DSN only as candidates for the operator, who can request
a match. A report
correlated to a message but impossible to reconcile with it (several recipient reports, none for
the message's recipient; no interpretable status) appends `dsn_unmatched` to that message.

Classification is conservative. `Action: failed` with a 5.x.x status is a `hard_bounce`, with a
4.x.x status a `soft_bounce`; `Action: delayed` is `deferred` (or `connection_failure`); delivered,
relayed and expanded reports record nothing; a temporary failure never becomes permanent. An ARF
report of type `abuse` correlated to an exact message is a `complaint`. One failure-scope
classifier serves Postfix log and DSN evidence alike.

Everything for one file (event, state projection, webhook outbox row, suppression policy) is one
transaction, keyed by the Maildir unique file name, so crashes, retries, restarts and duplicate
notifications never duplicate anything. Processed files stay in `done/` for
`DELIVERY_DSN_RETENTION_DAYS` (7 by default); the database record is the durable one.

## 9.5 Transport reconciliation

Lost log events must never leave a job permanently `dispatched`. Go periodically reconciles messages that are still `queued`, `submitted` or `deferred` against three sources: the recorded events, the Postfix queue snapshots, and inbound DSNs.

When a queue ID disappears from Postfix without a terminal event having been ingested:

1. Go does **not** assume successful delivery.
2. It rechecks after a configurable grace interval and across more than one fresh queue snapshot, re-scanning logs and DSNs for an authoritative outcome.
3. If none can be recovered, it appends an explicit reconciliation event and sets the message to **`outcome_unknown`**.
4. The condition is shown to the operator.

`outcome_unknown` means Smarthost cannot determine the final SMTP result. It is not a delivery-success state. A later authoritative transport event or DSN supersedes it.

Messages that were never acknowledged by Postfix (an ambiguous submission) are checked against the log before resubmission, so a message is never submitted twice.

---

# 10. Message State and Event History

A message should not be represented by one status field that is repeatedly overwritten without history.

Email delivery is a sequence of events.

Example:

```text
10:01  message_created
10:01  message_queued
10:01  submitted_to_postfix
10:01  postfix_queued
10:02  deferred
10:22  delivery_attempt
10:23  remote_accepted
12:17  open_recorded
12:19  click_recorded
```

Use two concepts.

## 10.1 `messages`

One row per recipient, containing the current state and identifiers:

- `id`;
- `send_job_id` and the originating `send_job_recipient_id`;
- external recipient reference;
- recipient address;
- VERP token and return path;
- tracking token;
- Postfix queue ID;
- current status;
- created/resolved timestamps.

Current statuses are:

| Status | Kind |
|---|---|
| `created`, `queued`, `submitted`, `deferred` | in progress |
| `remote_accepted`, `soft_bounced`, `hard_bounced`, `complained` | terminal transport outcomes |
| `outcome_unknown` | terminal knowledge state, not success |
| `failed` | immediate submission failure |
| `suppressed` | deliberately not submitted |

The current status is a projection of the events. A newer event can only move it "forward", so out-of-order arrival cannot regress it.

## 10.2 `message_events`

Append-only event history. Each record holds:

- `id`;
- `message_id`;
- event type;
- event source and source event key (for de-duplication);
- failure scope, for deferrals and bounces;
- SMTP code;
- enhanced SMTP status code;
- remote host;
- diagnostic text;
- metadata JSON;
- the time it occurred and the time it was recorded.

The event types are:

| Group | Event types |
|---|---|
| Message lifecycle | `message_created`, `message_queued`, `message_suppressed`, `submission_failed`, `submitted_to_postfix` |
| Postfix transport | `postfix_queued`, `delivery_attempt`, `deferred`, `connection_failure`, `remote_accepted` |
| Bounces and complaints | `soft_bounce`, `hard_bounce`, `complaint` |
| Reconciliation | `transport_outcome_unknown` |
| Engagement | `open_recorded`, `click_recorded` |
| DSN | `dsn_unmatched` |

The full vocabulary, with sources and status effects, is in `docs/contracts/status-vocabulary.yaml`.

Append-only event history makes operational debugging and statistics much more reliable.

---

# 11. Correct Meaning of Delivery

The user interface and API must not equate SMTP acceptance with proven human delivery.

When a receiving mail server returns a successful SMTP response, Smarthost knows that the receiving system **accepted responsibility for the message**.

It does not prove:

- inbox placement;
- that the message avoided a spam folder;
- that the intended person saw it;
- that the intended person read it.

For this reason the preferred status name is `remote_accepted`, not an absolute claim such as `read` or `delivered_to_inbox`. Likewise, `outcome_unknown` must never be shown as delivered.

---

# 12. Open and Click Tracking

The original requirements include knowing when recipients engage with a message. Postfix and DSNs cannot provide this information.

Smarthost therefore needs HTTP engagement tracking. It applies only when the send job enables it (§8.8).

## 12.1 Tracking-token model

Each tracked message receives an **opaque, cryptographically secure random token** with at least 192 bits of entropy. Tokens are not signed, so no signing key needs to be shared between Go and Symfony.

The tracking endpoints:

- validate the token format;
- look up the random token;
- reject unknown, expired or inapplicable tokens, without recording an event or redirecting;
- map the token to the stored message or link;
- never expose raw recipient email addresses.

A token is accepted only if it is well formed, names a message that was handed to Postfix and whose
send job enables that kind of tracking, and, when `APP_RETENTION_TRACKING_DAYS` is set, the message
is not older than that. Anything else is answered exactly as an unknown token would be. The
responses never contain recipient, client or message data, are never cacheable (`no-store`), set no
cookies and need no JavaScript. `HEAD` requests are answered but never recorded. Tracking tokens are
not logged: nginx writes tracking URLs to its access log with the token redacted, and the
application does not log route parameters.

### Recording rule

Events are append-only. Each recorded open or click is a separate `open_recorded` or
`click_recorded` event from the `tracking_endpoint` source; nothing about the requester (IP address,
user agent) is stored. Automated repetition (prefetchers, link scanners, reloads) must not grow the
table without bound, so a request is still answered but **not recorded** when:

- the same message had an open recorded in the last 60 seconds (opens);
- the same message and link had a click recorded in the last 10 seconds (clicks);
- the message already has 1000 events of that type;
- the requesting address exceeded 1200 tracking requests per minute.

This is a bound on storage, not a claim about how many people engaged. Recording never changes a
message's transport status.

## 12.2 Recorded opens

HTML mail may contain a small tracking image:

```text
/t/o/{token}.gif
```

Loading the image creates an `open_recorded` event (subject to the recording rule). The response is
always the same transparent 1×1 GIF, whether or not the token is known.

The UI must describe this accurately as a **recorded open**, not proof that a human definitely read the email.

Open data is affected by:

- image blocking;
- image proxying;
- privacy preloading;
- security scanners;
- caches.

## 12.3 Recorded clicks

Eligible HTML links are rewritten to:

```text
/t/c/{token}/{link_index}
```

The server records a `click_recorded` event (with the link index), then redirects to exactly the
destination stored in the server-side link map for that message and index. The redirect target never
comes from the URL, a query string, the `Host` or the `Referer`, which prevents **open redirect**
abuse. An unknown token, an index that the message does not have, or any other URL under `/t/`
gets the same plain 404. Unsubscribe links are never rewritten, so they are never click-tracked.

Dashboards report messages with at least one recorded open or click, the total recorded events,
the first and last recorded times and clicks per stored link. A click is never counted as an open.

No raw recipient email address is embedded in any public tracking URL.

---

# 13. Smarthost Suppression Versus Client Subscription State

These concepts must remain separate.

## Client application state

Examples:

- unconfirmed;
- confirmed;
- opted out (via the client's own unsubscribe endpoint);
- mailing-list memberships.

## Smarthost suppression state

Examples:

- hard bounce;
- complaint;
- repeated soft bounce;
- operator block;
- abuse block.

A user unsubscribing from a newsletter is principally a client business event.

A mailbox producing a hard bounce is principally a Smarthost transport/reputation event.

The client can consume Smarthost events and update its own records accordingly, without both databases owning the same business state.

## 13.1 Address matching

Suppression matching uses the same normalisation as duplicate detection:

- trim surrounding whitespace;
- preserve the local part exactly;
- lower-case the domain.

The exact rule (D-32): trim only ASCII whitespace (space, tab, LF, VT, FF, CR); split at the final `@`; keep the local part byte-for-byte; lower-case an ASCII domain; convert a domain with non-ASCII characters to A-labels using UTS #46 non-transitional processing and lower-case the result; if that conversion fails there is no normalised address. `docs/contracts/address-normalization-vectors.json` holds shared test vectors that the PHP, Python and Go implementations must all pass.

`John@example.COM` therefore matches as `John@example.com`, not `john@example.com`. Adopting case-insensitive local-part matching later would require an explicit, documented policy.

## 13.2 Repeated soft bounces

By default, an address is suppressed after **3 consecutive recipient-specific soft bounces within a rolling 30-day period**. A later `remote_accepted` outcome resets the count, and both the count and the window are configurable.

Only mailbox-specific temporary failures count, such as a full or temporarily disabled mailbox. These do **not** count:

- provider-wide rate limiting;
- connection failures;
- DNS failures;
- temporary remote infrastructure failures;
- general domain-level deferrals.

Each of these is recorded with its own failure scope, because none of them shows that a specific mailbox should be suppressed.

The rule is evaluated **globally** (§13.3): across every client's messages to the same normalised
address. Each message counts once, and soft bounces of other scopes neither count nor reset the
sequence. Because a full or disabled mailbox is a temporary condition, the resulting
`repeated_soft_bounce` suppression is **temporary**: it expires after the configured window
(`DELIVERY_SOFT_BOUNCE_SUPPRESSION_WINDOW_DAYS`, 30 days by default).

## 13.3 Global suppression policy (D-30)

Automatic transport suppressions are **global across the whole Smarthost installation**
(`suppressions.client_id` is NULL). They apply to every current and future client. A global
address suppression is created for:

1. an authoritative, **recipient-specific** permanent failure (`hard_bounce` with failure scope
   `recipient`, for example 5.1.1 unknown mailbox), whether the evidence came from the Postfix log,
   an inbound DSN or an operator-resolved unmatched DSN;
2. a verified recipient **complaint** (an ARF report correlated to an exact message);
3. the repeated recipient soft-bounce rule (§13.2);
4. an explicit **recipient global opt-out** reported by an authorised client (§13.4).

No global suppression is created for a single soft bounce, provider-wide throttling, sender or IP
reputation rejections, domain-level policy failures, DNS, connection or TLS failures, remote
infrastructure failures, general domain deferrals, ambiguous outcomes, `outcome_unknown`,
validation results alone, or a DSN or complaint that is not confidently correlated.

A suppression is active while it is not lifted and not expired. Address suppressions match the
exact normalised address (the local part is never case-folded); domain suppressions, which only an
operator creates, match the lower-cased domain. Any active applicable suppression stops
submission: Go checks when it creates a message and again just before submitting it, and records
`message_suppressed`. Mail that Postfix has already accepted is not recalled. Independent
suppressions (different reasons or reporters) coexist as separate rows; lifting one never makes
the address sendable while another is active. Hard-bounce, complaint and opt-out suppressions are
indefinite until explicitly lifted.

Provenance is explicit: system suppressions name their source message and event; a client-reported
opt-out names its reporting client in `source_client_id` (never in `client_id`), with its creation
time, an optional client reference and an audit-log entry; operator actions are in the audit log
with the operator's identity.

## 13.4 Recipient global opt-out

An ordinary unsubscribe from a list, newsletter, campaign or client is client business state and is
never copied into Smarthost. Only when the recipient explicitly says *"do not send me email from
any source using this Smarthost installation"* may a client report it, with
`POST /v1/global-suppressions`. Smarthost then creates a global `recipient_global_opt_out`
suppression for the normalised address.

Because a multi-tenant Smarthost must not let any tenant suppress any address globally, the API is
available only to clients that an operator has granted the `can_submit_global_suppressions`
capability (default false for every client; changed only with an audited console command). Other
clients receive 403. The API accepts only an address and an optional external reference: a client
cannot create any other reason or a domain suppression. The response shows only the client's own
opt-out.

Creating an opt-out is a do-not-contact operation, not work creation: a trusted client may report
one even while `pending_approval` or `suspended` (D-37). Lifting one makes an address sendable
again, so only an `active` or `throttled` trusted client may lift its own opt-outs. Closed clients
cannot authenticate.

Requests are idempotent (`Idempotency-Key`) and concurrency safe. Each accepted request is recorded
durably, independently of the suppression row (D-38): the same key with the same request always
returns the recorded result, even after the opt-out was lifted, and never creates a new
suppression; the same key with a different request is rejected.

## 13.5 Lifting suppressions

Suppressions are never deleted to unsuppress an address; `lifted_at` is set and the history kept.
The reporting client may lift its own opt-out (`POST /v1/global-suppressions/{id}/lift`) when the
recipient withdraws it; this is idempotent and audited, and does not affect any other suppression of
the address. An operator may lift any suppression with an audited console command and a written
note.

---

# 14. Database Ownership and Core Schema

Symfony/Doctrine is the sole migration authority for schema objects. Database roles and grants are created by infrastructure bootstrap tooling (§5).

Go and Python must be built against the schema contract and may not independently modify it.

The core Smarthost schema includes:

| Area | Tables |
|---|---|
| Tenancy and identity | `clients`, `users`, `client_memberships`, `sending_domains`, `api_keys` |
| Validation | `validation_jobs`, `validation_addresses`, optional `validation_evidence`, `disposable_domains` |
| Sending | `send_jobs`, `send_job_recipient_batches`, `send_job_recipients`, `messages`, `message_links`, `message_events` |
| Transport ingestion | `unmatched_dsns`, `delivery_ingest_cursors` |
| Suppression and reputation | `suppressions`, optional `domain_reputation` |
| Metering | `usage_records` |
| Webhooks | `webhook_endpoints`, `webhook_events` (the transactional outbox), `webhook_deliveries` |
| Audit | `audit_log` |

Time-ordered UUIDs (UUIDv7) are used consistently across all three languages.

Timestamps use timezone-aware PostgreSQL timestamps and are handled consistently in UTC internally.

Important indexes include:

- validation rows by job;
- message rows by send job;
- unique VERP token and unique tracking token;
- Postfix queue ID;
- event history by message and time;
- unique event source + source event key;
- unique normalised recipient address per send job;
- client/status indexes for jobs;
- API key hash.

Raw API keys must never be stored.

Dashboard users log in with an email that is unique regardless of case. This is a login-identity rule, and it is never used as the SMTP mailbox-normalisation rule.

## 14.1 Retention

Rendered content is transient (§8.12). Phase 0 hard-codes no final long-term retention period for:

- validation history;
- suppressions;
- message metadata and events;
- tracking tokens and links;
- unmatched DSNs;
- audit logs;
- usage records;
- webhook delivery history (`APP_RETENTION_WEBHOOK_DELIVERIES_DAYS`, specification 2.8).

Each period is configurable. The operator's compliance specification will set the production policy. Until then, no automatic deletion takes place.

The exact schema is in `docs/schema/reference-schema.sql`, and the ERD, tenant ownership and grant matrix are in `docs/schema/schema.md`.

---

# 15. Public API

The API is versioned from the first release:

```text
/v1/...
```

The client is always identified by the API key and never by a `client_id` in the request.

The initial endpoints are:

```text
POST /v1/validation-jobs
GET  /v1/validation-jobs/{id}
GET  /v1/validation-jobs/{id}/addresses

POST /v1/send-jobs                     (create, collecting)
POST /v1/send-jobs/{id}/recipients     (≤ 500 rendered recipients per request)
POST /v1/send-jobs/{id}/submit         (seal and queue)
GET  /v1/send-jobs/{id}
GET  /v1/send-jobs/{id}/messages

GET  /v1/messages/{id}/events

POST /v1/webhooks/test                 (one named endpoint: webhook_endpoint_id required)

POST /v1/global-suppressions           (trusted clients only: recipient global opt-out)
GET  /v1/global-suppressions/{id}
POST /v1/global-suppressions/{id}/lift
```

Job processing is asynchronous. The client either polls or receives a signed webhook when a job changes state.

Request bodies are limited to 10 MiB. The full contract is `docs/api/openapi.v1.yaml`.

## Idempotency

Creating a validation job, creating a send job, and uploading a recipient batch each require an idempotency key, so that a network retry cannot create duplicate jobs, duplicate recipients or duplicate sends. Submit is naturally idempotent: submitting a job that is already sealed returns its current state.

A replay (same key, same body) has no repeated side effect and returns the same created resource with the same status code, the same `Location` where applicable, and `Idempotent-Replayed: true`. For resource creation the body may show the resource's current state rather than a byte-identical copy of the first response; Smarthost does not store response bodies. A recipient-batch replay reproduces the original batch result, including the running total at that time (D-34).

## Webhooks

Clients register one or more webhook endpoints. Each endpoint can be enabled or disabled, chooses the event types it subscribes to, and has its own signing secret.

The signing secret is **encrypted at rest** with application-managed keys. It cannot simply be hashed, because Smarthost must hold it to compute the HMAC. Rotation is supported: during an overlap window, each request carries signatures from both the new and the previous secret.

Delivery uses a **transactional outbox**:

1. When Python, Go or Symfony makes a state change that produces a client-visible event, it inserts a `webhook_events` row in the same PostgreSQL transaction.
2. A dedicated, long-running **Symfony webhook worker**, built from the same application image, consumes these rows. It creates delivery records, signs and sends each request, retries failures with exponential backoff, and records the outcome.
3. Python and Go never send HTTP webhooks. No message broker is used.

The initial events are:

- `validation.completed`;
- `validation.failed`;
- `send.completed`;
- `send.failed`;
- `message.hard_bounced`;
- `message.complained`.

### Webhook delivery contract (specification 2.8)

**Body.** Each request is an HTTPS POST of one `WebhookEvent` (`id`, `type`, `created_at`, `data`).
The `data` depends on the event:

- `validation.*`: the validation job as `GET /v1/validation-jobs/{id}` shows it;
- `send.*`: the send job;
- `message.*`: the message, plus the message event that caused the change (the first hard bounce
  or complaint);
- `webhook.test`: `{}`.

`data` is the subject's state when the worker fans the event out; clients poll the API for later
state. No secret, API key, tracking token or VERP token is ever included.

**Headers.**

- `Content-Type: application/json` and `User-Agent: Catto-Mail-Smarthost/<version>`;
- `Smarthost-Event-Id`, the de-duplication key;
- `Smarthost-Event-Type`;
- `Smarthost-Delivery-Id` and `Smarthost-Delivery-Attempt`, for diagnostics only;
- `Smarthost-Signature: t=<unix seconds>,v1=<hex>`, where `v1` is HMAC-SHA256 over the exact bytes
  `<t>.<body>` with the endpoint's signing secret. During a rotation overlap a second `v1` signed
  with the previous secret is added. Receivers compare in constant time, verify before parsing and
  reject timestamps more than 5 minutes from their clock.

**At-least-once.** Each event produces exactly one *delivery record* per endpoint. The database's
unique (event, endpoint) index guarantees this even when several workers run. The HTTP request,
however, can be sent more than once: a request whose result was not recorded (a timeout, or a
worker that stopped) is sent again. Receivers therefore de-duplicate on `Smarthost-Event-Id`, and
Smarthost never claims exactly-once delivery.

**Fan-out.** An event goes to the client's endpoints that are enabled and subscribed to its type
when the worker fans it out, normally within seconds. A `webhook.test` goes only to the one endpoint
named in the request, if it is still enabled, regardless of subscriptions. There is no client-wide
test.

**Retries.**

- Any 2xx response delivers the event.
- 408, 425, 429, 5xx, timeouts, refused or reset connections, TLS errors and temporary DNS failures
  are retried after min(`APP_WEBHOOK_RETRY_MAX_SECONDS`, `APP_WEBHOOK_RETRY_BASE_SECONDS` ×
  2^(attempt−1)) ±10 %, or after a `Retry-After` of up to that maximum.
- Other responses fail the delivery without retry: other 4xx, and 3xx (redirects are never
  followed).
- After `APP_WEBHOOK_MAX_ATTEMPTS` attempts the delivery fails.
- Failed deliveries are kept and shown to the client and the operator. Endpoints are never disabled
  automatically.

**Destinations (SSRF).** Before every attempt the endpoint's host is resolved, and the request is
refused if any address is non-public:

- loopback, unspecified and private (RFC 1918) addresses;
- carrier-grade NAT;
- link-local addresses, including the cloud metadata address;
- multicast, reserved and documentation ranges;
- IPv6 unique-local addresses;
- IPv4 embedded in IPv6.

The connection is pinned to the checked address, so a DNS answer that changes later cannot
redirect it. Only https is used in production. In development and test, only the host names listed
in `APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS`, such as the local receiver fixture, may be private; that
setting is a startup error in production.

**Limits.**

- The request times out after `APP_WEBHOOK_TIMEOUT_SECONDS`.
- At most 64 KiB of the response is read, and 1 KiB of it is kept for diagnostics.
- A batch of requests is sent concurrently, so one slow endpoint does not stall the others.

**The worker.** The worker runs `smarthost:webhook:work` in the `smarthost-webhook-worker`
container, as its own database role. Each round it:

- fans out pending events;
- claims due deliveries (`FOR UPDATE SKIP LOCKED`, with a lease). The incremented attempt count is
  the fencing token;
- sends them with no database transaction open;
- records each outcome only if it still holds the lease.

A crashed worker's deliveries are claimed again when the lease expires. `SIGTERM` stops new claims.
The container's health check requires the worker loop to have run recently, and each worker records
a heartbeat for the operator dashboard.

**Management.** Client admins manage endpoints in the client dashboard:

- create endpoints and choose their event types;
- enable, disable and edit them;
- rotate the signing secret, which is shown once;
- send a test event.

The audited console commands do the same.

`POST /v1/webhooks/test` requires `webhook_endpoint_id` and records a `webhook.test` for that one
endpoint. It returns the event id at once (202) and never calls the endpoint itself.

- Another client's endpoint answers 404, exactly as a missing endpoint does.
- A disabled endpoint answers 409 `webhook-endpoint-disabled`, and nothing is recorded.
- A request without an endpoint id fails validation (400/422) and is never sent to every endpoint.

**Decisions (specification 2.8).**

- **No endpoint-management API.** The public API has no endpoint-management (CRUD) operations.
  Endpoints are set up as administration, in the dashboard or the audited console. Runtime
  integration uses the public API and the received webhooks.
- **No suppression lookup.** There is no API to ask whether an arbitrary address is suppressed.
  Smarthost enforces suppressions at send time, and the client learns the outcome from its message
  state and events.
- **Retention.** Webhook delivery history follows `APP_RETENTION_WEBHOOK_DELIVERIES_DAYS`. Empty
  means no automatic deletion until a production retention policy sets a period.
- **Production values.** Production `APP_WEBHOOK_*` values are chosen in Phase 8. A distinct connect
  timeout and a response-header size bound are later hardening.

---

# 16. Security and Abuse Controls

An email validation and sending platform has unusually high abuse potential, so operator controls are part of the architecture rather than an optional SaaS feature.

Required controls include:

- rootless Podman;
- least-privilege containers and least-privilege database roles;
- secrets outside images and repositories;
- TLS;
- authenticated SMTP submission;
- API key hashing;
- per-client scoping;
- human dashboard users who sign in without passwords, by single-use emailed links, with client memberships and installation-wide roles and permissions; never API keys as browser credentials;
- API and volume rate limits;
- manual account approval before substantial production volume;
- account suspension;
- emergency throttling;
- bounce-rate monitoring;
- complaint-rate monitoring;
- verified sending domains (DNS TXT challenge);
- DKIM keys held only by OpenDKIM;
- encrypted webhook signing secrets;
- operator audit log;
- global suppression capability;
- recipient global opt-outs accepted only from clients an operator has explicitly authorised.

Before the service is offered publicly, it will also require an acceptable use policy and abuse-handling process.

## SaaS operations (specification 2.10)

Phase 9 extends the one client model for external clients. There is no second account system.
Operator procedures are in `docs/production/onboarding.md` and the runbook.

**Client lifecycle.** A client is `pending_approval`, `active`, `throttled`, `suspended` or `closed`.

| From | To | Permission |
|---|---|---|
| `pending_approval` | `active` (approval) or `closed` (rejection) | `PLATFORM.CLIENT.APPROVE` |
| `active` | `throttled` or `suspended` | `PLATFORM.CLIENT.RESTRICT` |
| `throttled` | `active` or `suspended` | `PLATFORM.CLIENT.RESTRICT` |
| `suspended` | `active` or `throttled` (reactivation) | `PLATFORM.CLIENT.APPROVE` |
| any but `closed` | `closed` | `PLATFORM.CLIENT.APPROVE` |

- Nothing returns to `pending_approval`, and `closed` is final.
- Every change needs a written reason, is re-checked under a row lock and is audited with the
  reason (`client.approved`, `client.throttled`, `client.suspended`, `client.reactivated`,
  `client.unthrottled`, `client.closed`).
- `status_changed_at`, `approved_at` and `closed_at` are recorded.

What each state means:

- **Pending approval:** the client signs in and reads, but creates no work.
- **Throttled:** the client keeps working within its quotas, but more slowly. Its API request
  rate is capped (`APP_THROTTLED_CLIENT_API_RATE_PER_MINUTE`), and the delivery daemon starts its
  messages at the throttled-client rate (`DELIVERY_THROTTLED_CLIENT_RATE_PER_MINUTE`).
- **Suspended** (the per-client kill switch):
  - the client still signs in and reads, but every work-creating call answers 403;
  - the delivery daemon loses the lease of its running send jobs at the next renewal and starts
    no new message;
  - the validator stops claiming its jobs;
  - queued work waits for reactivation;
  - webhooks about work that already happened are still delivered.
- **Closed:** the client's API keys no longer authenticate.

The installation-wide kill switch remains the Phase 8 delivery pause.

**Public onboarding gate.**
- `APP_PUBLIC_ONBOARDING_ENABLED` is false by default and in the production profile.
- No public registration route exists. The one gated entry point can only create a
  `pending_approval` client of origin `public_application`.
- Enabling it is an owner decision, with the criteria listed in `docs/production/onboarding.md`.

**Service policy.**
- `APP_ACCEPTABLE_USE_POLICY_VERSION` names the policy version in force. Smarthost stores
  versions and acceptances, never policy text.
- A client that requires acceptance cannot be approved until it has accepted that version.
- A client admin accepts in the dashboard, or an operator records an acceptance obtained
  elsewhere, with a reference.
- Operator-created active clients and clients created before Phase 9 are exempt.

**Client metadata.**
- Contact, billing and abuse contact addresses, and the origin.
- Operator-only private notes, never shown to the client.

**Limits and quotas.**
- Per client (NULL = none):
  - API requests per minute;
  - validation jobs per day and addresses per day and month;
  - send jobs per day and recipients per day and month;
  - maximum recipients per send job;
  - maximum API keys, webhook endpoints and sending domains.
- Installation ceilings always apply; a client limit can only lower them.
- Volume quotas are counters admitted by one atomic upsert inside the transaction that creates
  the work, so concurrent requests cannot jointly exceed a quota, and a failed request releases
  its admission.
- Count limits are re-checked under a lock on the client.
- A refusal is `429 quota-exceeded` with `Retry-After` and a `quota` member naming only the
  client's own figures.
- Changes are audited before and after.

**API keys.**
- Each key has a name, an optional expiry, its last use and revocation.
- The secret is shown once; only its hash is kept.
- Rotation is: create a new key, deploy it, revoke the old one.
- Client admins manage their own keys; operators need `PLATFORM.CLIENT_KEY.MANAGE`.

**Usage and billing boundary.**
- `usage_records` remains the only metering source: validation addresses per job, and one
  `message_submitted` unit per message accepted by Postfix (enforced unique).
- Summaries cover the current day, the current month, the previous month and custom ranges
  (UTC).
- Reconciliation compares metered units with validation jobs and accepted messages and names
  every inconsistency.
- Billing statements (draft → finalized → exported, or void) freeze an ended period's
  quantities.
  - A statement is finalized only after a consistent reconciliation.
  - Export needs an external reference; voiding needs a reason.
- Smarthost holds no prices, taxes, invoices or payment data, and no billing provider is chosen.
- Usage exports are deterministic JSON or CSV, and every export is audited.

**Abuse and reputation monitoring.**
- Every 15 minutes (a production timer), per client and window (24 hours, 7 days), Smarthost
  counts:
  - messages and validation addresses;
  - hard and soft bounces;
  - deferrals and provider-policy failures;
  - complaints, suppressions and unknown outcomes.
- Alerts (warning and critical) cover:
  - the hard-bounce, complaint and deferral rates, with numerator and denominator and a minimum
    sample;
  - a volume increase over the previous week's daily average.
- Alerts resolve when the metric recovers. Operators acknowledge them with a note.
- **Alerts never act on a client:** the operator decides.

**Webhook hardening.** Endpoint limits, and endpoint health (recent deliveries, last success and
failure, failures since the last success) in both dashboards.

**Client documentation.** The integration guide and the OpenAPI contract are public at
`/docs/api`. They state plainly:
- remote acceptance is not inbox delivery;
- a recorded open is not proof of reading;
- validation is not consent;
- an ordinary unsubscribe is the client's own state, not a Smarthost global suppression.

**Permissions.**
- New keys: `PLATFORM.CLIENT.APPROVE`, `PLATFORM.CLIENT.RESTRICT`, `PLATFORM.CLIENT_LIMIT.MANAGE`,
  `PLATFORM.CLIENT_KEY.MANAGE`, `PLATFORM.USAGE.VIEW`, `PLATFORM.USAGE.EXPORT`,
  `PLATFORM.ABUSE.VIEW` and `PLATFORM.ABUSE.MANAGE`; the OPERATOR role holds them.
- Console commands name the acting operator and check the same permissions.

---

# 17. Testing Strategy

The entire pipeline must be testable without sending public Internet mail.

## Mailpit

Development Postfix sends into Mailpit so that messages can be inspected locally.

## Fake SMTP server

A deterministic SMTP test service should reproduce cases such as:

- `250` success;
- `421` service unavailable;
- `450` temporary mailbox failure;
- `451` temporary processing error;
- `550` permanent mailbox rejection;
- timeout;
- connection refusal;
- accept-all behavior.

## Python tests

Test:

- syntax parsing;
- typo suggestions and reason codes;
- DNS states;
- Null MX;
- DNS temporary failures;
- SMTP status mapping;
- retries;
- lease renewal and reclaim;
- per-domain throttling;
- accept-all classification.

## Go tests

Test:

- VERP generation;
- queue-ID correlation;
- Postfix event parsing and source-key de-duplication;
- DSN parsing;
- bounce classification and failure scope;
- append-only event generation;
- tracking instrumentation and link-rewriting exclusions;
- queue-snapshot reconciliation and `outcome_unknown`;
- pacing and backoff.

## Symfony tests

Test:

- authentication;
- tenant scoping;
- API idempotency (job creation, recipient batches, submit);
- batch limits and duplicate detection across batches;
- sending-domain TXT verification;
- tracking tokens;
- click redirect safety;
- webhook signatures and secret rotation;
- schema migrations.

## End-to-end integration tests

The first meaningful milestone is a complete local pipeline:

```text
API validation request
        ↓
Python worker
        ↓
validation result
        ↓
API send job: create → recipient batches → submit
        ↓
Go
        ↓
Postfix + OpenDKIM
        ↓
Mailpit/fake SMTP
        ↓
message events (+ reconciliation)
        ↓
webhook outbox → Symfony webhook worker
        ↓
Symfony dashboard/API
```

Include DKIM signing, the milter-failure tempfail-and-retry case, and reconciliation of deliberately lost log events.

---

# 18. User Interfaces

## Client dashboard

The client-facing Symfony UI should eventually provide (Phase 6 status below):

- validation uploads;
- validation job history;
- result breakdowns;
- suggested typo corrections;
- downloadable result/cleaned files;
- send history;
- recipient/message event timelines;
- bounce and deferral statistics;
- recorded open/click statistics;
- API key management;
- webhook endpoint management;
- usage information;
- sending-domain verification and DKIM status.

Large tables require server-side pagination, sorting and filtering.

Symfony UX, LiveComponent, Twig and Stimulus should be used to avoid repeating hand-written AJAX/table plumbing.

**Implemented in Phase 6.** Sign-in is passwordless (specification 2.7; see *Dashboard sign-in and
access control* below). Client pages live under `/dashboard/c/{client id}`. A client that the
user may not see is answered exactly like one that does not exist, and every query carries the
client's id. Viewers can read, members can also run a sending-domain DNS check, and admins can also
lift the client's own recipient global opt-outs (D-37 still applies). The pages are:

- overview: validation and sending counters, recorded engagement of the last 30 days, sending
  domains and usage this month;
- validation jobs with filters, job detail with paginated, filterable and sortable address results,
  and a CSV export of the (filtered) results, streamed in batches, with exactly the API's
  `ValidationAddress` fields;
- send jobs with filters, job detail with transport outcomes, recorded engagement, clicks per link
  and the paginated message list, and the per-message event timeline;
- suppressions: those of this client and the opt-outs it reported, plus this client's messages that a
  suppression stopped, with only the broad reason of a global suppression (never who reported it);
- sending domains with the verification record, status, DKIM status and a DNS check;
- usage per month and the individual usage records.

Lists use **keyset** (cursor) pagination with whitelisted sort and filter names. Browser upload of
validation lists and browser management of API keys and webhook endpoints are not part of Phase 6
(the API and the audited console commands remain); LiveComponent was not needed.

## Operator dashboard

The operator requires a separate view containing:

- new-client review queue;
- client suspension/throttling;
- cross-client bounce and complaint rates;
- Postfix queue depth and queue-snapshot freshness;
- Python validation backlog;
- Go and webhook-worker health;
- unmatched DSNs and their resolution;
- global suppressions and their provenance, with audited lifting;
- `outcome_unknown` messages and log gaps;
- failed webhooks;
- audit history.

**Implemented in Phase 6** (each page requires a permission key; see below): a system overview built from durable
database signals (validation and send work queues, worker leases, the newest results, the
Postfix-log ingest cursor, messages per status, bounce/deferral/complaint rates per client over
7 days, active suppressions, unmatched DSNs and the webhook outbox), so the page stays fast and usable
when a worker is down; clients with status changes and the opt-out capability (each through the
existing audited services); the unmatched-DSN workflow; suppressions with operator provenance,
operator blocks and lifting with a required note; the audit log with secret-like values redacted
on display; and webhook deliveries (pending, retrying, delivered, failed) with worker heartbeats
(Phase 7). The
Postfix queue snapshots are read only by the Go daemon, so the overview shows the messages Smarthost
considers to be in Postfix rather than the queue depth itself.

Every dashboard change is a POST with a CSRF token. Dashboard responses carry a Content Security
Policy (same-origin scripts and styles only, with a per-request nonce for the import map; no CDN),
refuse framing and are never cached; error pages are generic.

## Dashboard sign-in and access control (specification 2.7)

There are no passwords. At `/dashboard/login` a user enters an email address; if that address
belongs to an enabled user, Smarthost emails a **single-use sign-in link**. The answer is the same
whether or not an account exists. The link:

- carries a 256-bit random token, of which only the SHA-256 is stored;
- expires after `APP_LOGIN_LINK_TTL_SECONDS` (15 minutes by default) and works once;
- is built from `SMARTHOST_PUBLIC_BASE_URL`, never from the request's `Host` header;
- is not consumed by a `HEAD` request (mail-security scanners).

Requests are limited to 5 per address and 20 per client address per 15 minutes. The email is the
only mail the Symfony application sends: it goes through authenticated Postfix submission (its own
SASL account) and OpenDKIM, so in development it lands in Mailpit like everything else.

The address in `APP_ADMIN_EMAIL` can always request a link. Its account is created on first sign-in
and it receives the **ADMIN** role at every sign-in, so the configured administrator can always get
back in.

**Roles and permissions.** Operator pages each require a permission key, such as
`PLATFORM.CLIENT.MANAGE` or `PLATFORM.AUDIT.VIEW`. Roles grant sets of keys and a user holds the
keys of all their roles:

- **ADMIN** holds every permission, cannot be reduced, and is the only role with the `SYSTEM.*`
  keys: managing roles and granting ADMIN;
- **OPERATOR** starts with every `PLATFORM.*` key and can be edited;
- custom roles are created and edited on the Roles page.

Changes apply at each holder's next request. Users, their roles and their client memberships are
managed on the Users page, and every change is audited. Access to a client's own pages still comes
from the client membership (viewer, member, admin); `PLATFORM.CLIENT.VIEW` and
`PLATFORM.CLIENT.MANAGE` give access to every client.

---

# 19. Deliverability and Production Requirements

Smarthost production should run on its own VPS/IP rather than sharing reputation with unrelated services.

Before Internet sending begins, configure and verify:

- forward DNS;
- PTR/reverse DNS;
- SPF;
- DKIM through OpenDKIM, with per-domain selectors and production keys;
- DMARC appropriate to the rollout stage;
- TLS;
- bounce-domain MX/routing;
- inbound port 25;
- authenticated submission where required.

Production rollout must begin with owned seed addresses and very small volumes.

A large, unproven address list must never be used as the initial IP warm-up population.

## Production operations (specification 2.9)

**Configuration.** The *Production profile* of the environment contract becomes
`infra/production.env.example`. `smarthostctl prod init-env` copies it to the production host
and generates every secret there. `prod check`, and every rendering, refuses an unsafe production
configuration:

- an unverified-domain bypass, a webhook private-host allowlist, or any relayhost (Mailpit);
- missing or short secrets;
- a base URL that is not `https://` + the public hostname;
- placeholder or reserved names, or a non-public sending IP;
- the bounce domain equal to the public hostname;
- `TRUSTED_PROXIES` that is not the internal network.

The services repeat the rules that apply to them at start-up.

**Live delivery states:**

| State | When | Postfix | Go delivery daemon |
|---|---|---|---|
| capture | development and test | relays to Mailpit | claims and submits |
| held | production before activation | refuses outbound recipients temporarily (450 "live delivery is not activated"), except the dashboard sign-in mail | claims no send jobs |
| live | production after activation | delivers to the MX hosts | claims and submits within the warm-up ceiling |
| paused | the operator's emergency stop, in any state | nothing leaves the queue | no claims, no new submissions |

`smarthostctl prod live-enable` is the only way to activate live delivery:

1. It runs the activation preflight. Every check live delivery depends on must pass.
2. It audits the action.
3. It recreates Postfix and the delivery daemon.

Live activation and the emergency pause need `SYSTEM.DELIVERY.CONTROL` (ADMIN) and a written
note.

**Preflight** (`smarthostctl prod preflight`) reports PASS, WARN or FAIL for:

- host: OS, Podman version, lingering, and the low-port setting against the configured binds;
- runtime: images, health, migrations and grants;
- exposure: no published container port, the network matrix, private keys only in their owner;
- ingress: the socket units, nginx holding the sockets, Postfix's PROXY-protocol listener, and
  the two-source proof that nginx and Postfix see each client's own address;
- TLS: the certificates actually served;
- DNS identity: A records, PTR and forward-confirmed reverse DNS, SPF of the bounce domain,
  DKIM (the published key equals the installed one), DMARC, and the bounce domain's MX;
- Postfix: relay refusal, bounce-domain acceptance, sender ownership, held or live mode;
- delivery: the daemon's state.

It also prints the DNS checklist and a firewall ruleset.

**Production DKIM keys** are generated inside OpenDKIM. The workflow is generate, publish,
activate, wait, retire, with several domains and selectors at once. The private keys never leave
the OpenDKIM volume except in the backup.

**Postfix in production:**

- each submission account may use only its own envelope senders: the delivery daemon its VERP
  return paths, the web application `APP_MAIL_FROM`;
- port 25 accepts only the bounce domain and never relays;
- VRFY is disabled.

**Warm-up and throttling.** `DELIVERY_GLOBAL_RATE_PER_MINUTE` is a hard installation-wide
submission ceiling; the production profile starts at 5 per minute. Clients in status `throttled`
are paced at `DELIVERY_THROTTLED_CLIENT_RATE_PER_MINUTE`. The operator raises the limits stage by
stage on evidence; nothing raises them automatically.

**Observability.** The Go daemon records a durable heartbeat (`delivery_heartbeats`) for the
operator dashboard and `smarthost:ops:status`, so no page runs a Postfix command. It holds:

- the delivery state and the warm-up ceiling;
- the Postfix queue depth (active, deferred, hold, incoming) and the time of the newest queue
  snapshot.

**Backups, upgrades and seed tests:**

- `smarthostctl prod backup` saves PostgreSQL, the DKIM keys and tables, and the configuration
  with its secrets; `restore` puts them back.
- `upgrade` backs up, builds the new tag, recreates the containers (migrations and grants run at
  start) and checks; `rollback` returns to the previous images and the pre-upgrade database.
- Seed tests are operator-triggered sends to at most ten addresses the operator owns, for both the
  delivered path and the bounce path.

`smarthostctl test phase8-rehearsal` deploys the production topology locally without Internet
egress to rehearse all of this.

No implementation should claim reliable spam-trap detection because there is no dependable technical test for that.

---

# 20. Development Phases

The phases below are deliberately ordered to prove the most important architectural assumptions before substantial user-interface or SaaS work.

## Phase 0: Canonical Architecture and Contracts

**Purpose:** freeze responsibilities and interfaces before code begins.

Deliverables:

- canonical YAML specification;
- human-readable specification;
- repository skeleton;
- environment-variable contract;
- initial OpenAPI definition;
- database schema/ERD;
- event/status vocabulary.

Exit criteria:

- client/Smarthost ownership is unambiguous;
- Go owns tracked send execution;
- inbound bounce path is represented;
- message/event model is agreed.

---

## Phase 1: Rootless Podman Development Environment

**Purpose:** create the complete local topology before implementing business functionality.

Build:

- dedicated Smarthost Podman network;
- nginx reverse proxy;
- Symfony PHP-FPM container and a webhook-worker unit from the same image;
- Python container;
- Go container;
- Postfix container with the shared observability volume and DSN spool;
- OpenDKIM milter with a development test key;
- PostgreSQL with the infrastructure role bootstrap;
- Mailpit;
- fake SMTP service;
- the persistent pod definition, systemd user units, timers and health checks;
- verification of Postfix logging, rotation, queue snapshots and shared-volume ownership.

Exit criteria:

- services start and restart through systemd user units;
- Postgres, PHP-FPM, OpenDKIM, Python and Go have no public exposure;
- a test message sent by Postfix reaches Mailpit and nowhere else.

---

## Phase 2: Database and Symfony Foundation

**Purpose:** establish the durable contract on which Python and Go depend.

Build:

- Doctrine entities and migrations;
- clients, users and client memberships;
- hashed API keys;
- sending domains and TXT verification;
- validation jobs;
- send jobs, recipient batches and staged recipients;
- messages;
- message events;
- suppression table;
- webhook endpoints and outbox;
- audit log;
- API authentication;
- client scoping;
- idempotent job creation, batched recipient ingestion and submit.

Exit criteria:

- migrations succeed from an empty database;
- tenant-isolation tests pass;
- raw API keys are never persisted.

---

## Phase 3: Python Validation Engine

**Purpose:** solve the first real business problem: safely classify old addresses.

Implement:

- leased PostgreSQL job claiming;
- syntax validation;
- typo suggestions;
- DNS/MX/Null-MX handling;
- disposable-domain checks;
- role flags;
- SMTP probing;
- per-domain throttling;
- retry/backoff;
- evidence recording;
- job summaries.

Exit criteria:

- deterministic tests pass;
- no `DATA` command is ever issued;
- a synthetic 10,000-address job completes without uncontrolled memory or concurrency growth.

---

## Phase 4: Go/Postfix Delivery Pipeline

**Purpose:** prove tracked outbound transport.

Implement:

- leased send-job claiming;
- one message per staged recipient;
- VERP tokens;
- MIME assembly under the header contract;
- Postfix submission and rendered-content purge;
- queue-ID capture;
- Postfix event monitoring with persistent cursors;
- queue-snapshot reconciliation;
- append-only message events;
- destination-domain pacing;
- DKIM signing through OpenDKIM.

Exit criteria:

- expected messages arrive in Mailpit;
- queue IDs resolve back to the correct Smarthost messages;
- controlled 4xx/5xx cases produce the correct event history;
- messages are DKIM-signed, and a milter failure causes tempfail and retry;
- deliberately lost log events are reconciled or become `outcome_unknown`.

---

## Phase 5: Inbound DSNs, Bounces and Suppression

**Purpose:** close the SMTP feedback loop.

Implement:

- bounce domain;
- inbound Postfix path;
- DSN parser;
- VERP lookup;
- hard-bounce classification;
- soft-bounce classification with failure scope;
- suppression policy, including the repeated-soft-bounce rule;
- unmatched DSN recording and operator resolution;
- ARF complaint parsing;
- global suppressions with provenance (D-30), the trusted-client recipient global opt-out API and
  its operator-granted capability, and audited lifting;
- processed spool retention.

Exit criteria:

- synthetic DSNs map to the exact intended message;
- hard bounces create transport suppressions;
- temporary failures do not immediately create permanent suppressions;
- recipient-specific hard bounces and complaints create global suppressions, non-recipient failures
  do not, and a global suppression stops submission for every client;
- DSN processing is idempotent across crash, retry and restart.

---

## Phase 6: Tracking and Dashboards

**Purpose:** make the system observable and useful to a human operator/client.

Implement:

- validation result UI;
- send/message event UI;
- operator health UI;
- tracking pixel;
- random-token click redirect;
- recorded open/click statistics.

Exit criteria:

- large tables use server-side pagination;
- tracking URLs contain no raw email address;
- redirect endpoint cannot be abused as a generic open redirect;
- UI distinguishes SMTP acceptance from proven reading, and never shows `outcome_unknown` as delivered;
- tenant isolation and operator-only areas are proven with two clients;
- a 10,000-address validation job and a 10,000-message send job render page by page with bounded
  memory and query counts.

---

## Phase 7: First Client API Integration

**Purpose:** make the first client application a real external customer of Smarthost.

Integration expectations of a client application (how it meets them is decided in its own
repository; Smarthost defines only the public API and webhook contracts):

- it calls the public API with its API key, kept as a secret;
- it submits validation jobs and retrieves the results;
- it renders recipient content and submits batched send jobs;
- it receives webhooks, verifies their signatures and de-duplicates them by event id;
- it maps its own campaigns and subscribers to Smarthost through external references;
- it reconciles by polling when a webhook is missed.

Exit criteria:

- the full client workflow works without direct database access;
- either system can be deployed/restarted independently.

**Smarthost side implemented (specification 2.8).** The real Symfony webhook worker (see *Webhook
delivery contract*), `POST /v1/webhooks/test` and dashboard webhook management. The client-side
workflow is proven end to end by a deterministic external test client: an API-only driver plus an
independent webhook receiver, `smarthostctl test phase7-e2e`. Integrating the real first client
application, in its own repository, is still outstanding.

---

## Phase 8: Controlled Production Launch

**Purpose:** move from test capture to real Internet delivery without sacrificing reputation.

Implement/configure:

- dedicated Smarthost VPS;
- production Podman deployment (persistent pod, systemd user units);
- SPF;
- DKIM with production OpenDKIM keys;
- PTR;
- DMARC;
- TLS;
- bounce-domain routing;
- operational metrics;
- emergency throttling/suspension controls.

Rollout:

1. owned seed addresses;
2. tiny controlled sends;
3. inspect SMTP outcomes;
4. gradually increase volume;
5. begin client campaign traffic only after the infrastructure demonstrates stability and the compliance gate is satisfied.

Exit criteria:

- bounce path verified end to end;
- monitoring works;
- emergency stop controls work;
- production smoke tests pass.

**Repository side implemented (specification 2.9).** The production topology, tooling and
preflight described in section 19 (*Production operations*), the runbook
(`docs/production/runbook.md`), and a local rehearsal without Internet egress. Still pending, and
performed by the operator:

- the VPS, DNS, PTR and certificates;
- the live seed and bounce smoke tests;
- the traffic rollout.

---

## Phase 9: Public SaaS Hardening

**Purpose:** only after the first client has proven Smarthost in real production use, make the platform safe enough for paying external clients.

Build:

- client review workflow;
- stronger plan and rate enforcement;
- billing/usage integration;
- client API documentation;
- webhook hardening;
- sending-domain verification workflow refinements;
- acceptable-use enforcement hooks;
- abuse monitoring;
- client-facing account management.

Exit criteria:

- tenant isolation reviewed;
- usage metering reconciles correctly;
- operator kill switch is tested;
- external clients cannot bypass volume/safety controls.

**Repository side implemented (specification 2.10).** The SaaS operations of section 16 are
proven by the Phase 2 suite, including a two-client tenant-isolation review, an 8-process quota
race and query plans at representative scale, and by the production rehearsal. Public onboarding
remains disabled. Still pending, and decided by the owner:

- pricing and plans;
- the policy text;
- calibrating the thresholds on live traffic;
- the decision to open public onboarding.

---

# 21. Explicit Non-Goals for the First Product

Do not add the following simply because they are technically fashionable:

- Kubernetes;
- a home-grown SMTP server;
- direct database sharing between projects;
- Redis/RabbitMQ/Kafka before demonstrated need;
- complex microservice RPC;
- mail merge or template rendering inside Smarthost;
- machine-learning mailbox validity scoring in the first release;
- multiple sending-IP pools before one IP is operated successfully;
- unreviewed high-volume public sign-up;
- elaborate billing integration before the core service works.

---

# 22. Definition of a Complete Core Smarthost

The internal core product is complete when all of the following are true:

1. A client application authenticates through the Smarthost API.
2. A client application can submit a validation job of up to 10,000 addresses.
3. Python produces conservative evidence-based validation results.
4. A client application can submit a tracked send job with fully rendered recipient content in batches.
5. Go creates recipient messages and submits them to Postfix, with DKIM signing by OpenDKIM.
6. Postfix queue and delivery outcomes become append-only message events, with reconciliation for lost events.
7. Hard and soft bounces are mapped to the exact recipient message.
8. Transport suppressions prevent known bad addresses from being repeatedly sent to.
9. Recorded opens and clicks are available with accurate caveats.
10. The operator can see worker health, queue state, bounce rates and failures.
11. Client applications and Smarthost remain separately deployable and use no shared database.
12. The complete flow can be tested locally without sending public Internet mail.

At that point, Smarthost will be a real infrastructure component rather than merely an SMTP relay. It will provide validation, controlled delivery, evidence, event history and reputation protection for its client applications.

---

# 23. Revision History

| Version | Date | Summary |
|---|---|---|
| 2.0 | 8 September 2026 | Canonical architecture and development plan. |
| 2.10 | 7 October 2026 | Phase 9 repository-side public SaaS hardening (section 16, *SaaS operations*): client lifecycle with approval, throttling, suspension and closure (reasons, permissions, audit); disabled public-onboarding gate; service-policy acceptance; contact metadata and private notes; per-client limits with installation ceilings and concurrency-safe quotas (`429 quota-exceeded`); API-key names and expiry; usage summaries, reconciliation, provider-neutral billing statements and export; reputation metrics and alerts without automatic action; webhook endpoint health; the public integration guide at `/docs/api`. |
| 2.9 | 6 October 2026 | Phase 8 repository-side production readiness: production topology (standalone containers on internal and egress networks, network matrix, firewall), `smarthostctl prod`, the production configuration profile and safety rules, capture/held/live/paused delivery states with an audited, preflight-gated activation (`SYSTEM.DELIVERY.CONTROL`), the production preflight (host, runtime, exposure, ingress, TLS, DNS identity, SPF, DKIM, DMARC, bounce domain, Postfix, delivery), the socket-activated ingress that preserves client addresses (no published ports; SMTP to Postfix with the PROXY protocol; `TRUSTED_PROXIES` empty), the checked low-port host prerequisite, the launch decisions (IPv4 only, validator probing off, retention unset), production DKIM keys, Postfix submission sender ownership, the installation-wide warm-up ceiling and the throttled-client rate, emergency pause, `delivery_heartbeats` (Postfix queue depth), backups, upgrades, seed testing and the runbook; `APP_WEBHOOK_CONNECT_TIMEOUT_SECONDS` and a response-header bound. |
| 2.8 | 6 October 2026 | Phase 7 Smarthost side: the webhook delivery contract (body, headers, signature input, at-least-once with event-id de-duplication, fan-out timing, `webhook.test` addressing, retry and permanent-failure rules, SSRF destination policy with address pinning and no redirects, response and time limits, no automatic endpoint disabling), the real webhook worker (leases, fencing, heartbeats), dashboard webhook management. `webhook_deliveries` diagnostics; `webhook.test` names exactly one endpoint (`webhook_endpoint_id` required); `webhook_worker_heartbeats` and dashboard indexes; `APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS`, `APP_RETENTION_WEBHOOK_DELIVERIES_DAYS` (empty: no automatic deletion). Decisions: no endpoint-management API, no suppression-lookup API, production webhook values in Phase 8. |
| 2.7 | 6 October 2026 | Passwordless dashboard sign-in by single-use emailed links (through Postfix; Mailpit in development) with roles, permission keys and an editable access-control list; `APP_ADMIN_EMAIL` always receives ADMIN; OPERATOR replaces the operator flag; users, roles and memberships are managed in the browser; passwords removed. New tables `roles`, `role_permissions`, `user_roles`, `auth_login_tokens`. The development public URL is `https://localhost:8443`. |
| 2.6 | 5 October 2026 | Phase 6 tracking and dashboards: tracking-endpoint eligibility, identical answers for unknown tokens, the bounded recording rule (one recorded open per message per 60 seconds, one recorded click per message and link per 10 seconds, at most 1000 events of a type per message, a per-address limit that only skips the write) and token-free logging; the dashboards' scope, access model, keyset pagination and HTTP security; the `message_events` engagement index and read-only web access to the Postfix-log ingest cursor. No architecture change. |
| 2.5 | 5 October 2026 | Phase 5 corrections: DSNs and complaints correlate automatically only through Smarthost-issued identifiers; recipient-plus-sender evidence is only an operator candidate (D-36). Trusted clients may create recipient global opt-outs while pending approval or suspended, but lift them only while active or throttled (D-37). Durable opt-out request idempotency independent of the suppression row (D-38). |
| 2.4 | 4 October 2026 | Incorporates D-30: automatic transport suppressions (recipient-specific hard bounces, verified complaints, repeated recipient soft bounces) are global across the installation and apply to every client; non-recipient, temporary and ambiguous outcomes never create one; the repeated-soft-bounce suppression is temporary. Adds the `recipient_global_opt_out` reason, reported only by clients an operator authorised (`can_submit_global_suppressions`) through `/v1/global-suppressions`, with explicit provenance and audited lifting that never deletes history or overrides independent suppressions. Ordinary unsubscribes remain client state. Details inbound DSN/ARF parsing, correlation, classification, the unmatched-DSN workflow and spool retention. |
| 2.3 | 4 October 2026 | Incorporates D-35: a persistent pod lifecycle replaces the Quadlet pod/container units. The pod and containers are created once and then started and stopped as the same objects (also from Podman Desktop); a systemd user service starts the existing pod at boot; only an explicit recreate replaces them, and volume destruction stays separate. |
| 2.2 | 3 October 2026 | Incorporates D-31 (403 for work creation by pending-approval and suspended clients; workers claim only active or throttled clients' work), D-32 (exact address-normalisation rule with UTS #46 IDNA and shared test vectors), D-33 (validation usage metered by the validator on completion) and D-34 (idempotent-replay semantics). |
| 2.1 | 2 October 2026 | Incorporates the Phase 0 architecture decisions and resolves the mail-merge, tracking, unsubscribe, send-ingestion, webhook, DKIM and transport-reconciliation contracts. Main changes: fully rendered recipient content from client applications; batched recipient ingestion with `collecting`/`dispatched`/`completed` semantics; random tracking tokens; RFC 8058 one-click unsubscribe for subscription messages; webhook outbox and Symfony worker; nginx with PHP-FPM; OpenDKIM; sending-domain verification; transient rendered content; Postfix reconciliation with `outcome_unknown`; unmatched-DSN resolution; work leasing; event de-duplication; database role bootstrap; the live-sending compliance gate. The unsubscribe-signal event was removed. |
