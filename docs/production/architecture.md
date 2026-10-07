# How catto-mail works

Specification 2.11. Component names: [components.md](components.md). The same explanation is in
the dashboard under Help › *How an address and a message travel through the system*.

```text
                              INTERNET
                       |                      |
                  HTTPS 443                 SMTP 25 (bounces, complaints)
                       |                      |
      systemd ingress socket (smarthost-ingress.socket: holds both ports, real client addresses)
                       |                      |
                 nginx Frontend --(PROXY protocol, ingress network)--> Postfix :25
                       |                                                 |
                  FastCGI                                          bounce spool
                       v                                                 |
          Catto-mail Web Application                                     v
         (dashboard, API, tracking, /p)        +-------> Catto-mail Delivery Daemon --587--> Postfix --milter--> OpenDKIM
                       |                       |           ^       (reads Postfix log,              |
                       v                       |           |        queue snapshots, spool)          +--25--> recipients' MX
                PostgreSQL Database <----------+-----------+
                  ^          ^
                  |          |
  Catto-mail Email Validator  Catto-mail Webhook Worker --HTTPS--> client systems
  (DNS; SMTP probes only if enabled)

  Host agent (systemd user service on the host) <--console--> Web Application (requests, results)
```

Only nginx (and Postfix behind it) can be reached from the Internet. The database, the web
application's PHP processes and the workers are on an internal network with no Internet route.

## What happens

1. **An address list is uploaded** — through `POST /v1/validation-jobs`, or as an
   administrator address batch. The web application stores a validation job with one row per
   address (the batch keeps every input row, its provenance and duplicates), admits it against
   the client's quotas, and notifies the validator. Nothing is checked yet.
2. **An address is validated** — the validator leases a chunk (a crashed worker's chunk returns
   after the lease), then per address: normalisation, syntax, typo suggestion, domain and DNS
   (MX, null MX, address fallback), disposable and role flags, the optional SMTP probe with
   accept-all detection, classification. Temporary failures are retried with back-off before a
   final *temporarily unverifiable*. The job completes; a webhook tells the client.
3. **A send job is created** — the client creates the job (sender, class, tracking), adds
   recipients with their rendered content in batches of up to 500, and submits it. The web
   application checks the sending domain, quotas and limits, queues the job and notifies the
   delivery daemon.
4. **The delivery daemon picks it up** — it leases the job (only in LIVE mode, only for active or
   throttled clients, not during the emergency stop), creates each message exactly once, and
   skips suppressed recipients.
5. **Postfix accepts the message** — right before each submission the daemon checks suppressions
   again, builds the message (VERP return path `bounce+<token>@<bounce domain>`, tracking,
   List-Unsubscribe) and submits it on port 587 at the paced rate. Postfix returns a queue id:
   *submitted*. The daemon's responsibility ends here.
6. **OpenDKIM signs it** — Postfix passes it through OpenDKIM (milter), which adds the
   `DKIM-Signature` of the sending domain.
7. **Postfix contacts the recipient MX** — on port 25, with TLS when offered.
8. **The recipient MX accepts or rejects it** — Postfix logs the answer; the daemon reads the log:
   *remote accepted* (not "in the inbox"), *deferred* (Postfix retries for up to five days),
   *hard bounced* or *soft bounced*. A queue entry that vanishes without a logged outcome
   becomes *outcome unknown*, never "delivered".
9. **A later bounce arrives** — as a DSN to the VERP address, through port 25, nginx, Postfix, the
   bounce spool; the daemon parses it and matches the exact message by its token. A
   recipient-specific permanent failure creates a global suppression. Complaints (ARF) follow
   the same path. Unmatched ones wait for an operator.
10. **An open is recorded** — the message's tracking image `/t/o/<token>.gif` was fetched (at
    most once a minute per message is recorded). Image proxies and scanners also fetch images;
    it is never proof of reading.
11. **A click is recorded** — `/t/c/<token>/<n>` records the click and redirects only to the
    link's stored original address.
12. **A webhook is sent** — the event was written to the outbox in the same transaction; the
    webhook worker POSTs it, signed with the endpoint's secret, and retries for about a day.

## Who does what (and what they never do)

| | Does | Never |
|---|---|---|
| Web Application | accepts and records work, shows state, runs its own checks | sends mail or webhooks, validates, runs host commands |
| Host agent | host-side checks; carries out recorded requests with the production tooling | acts without a recorded, audited request (other than checks) |
| Validator | validates | sends mail |
| Delivery Daemon | prepares, paces, submits, records outcomes, processes bounces | talks to recipients' servers directly (Postfix does) |
| Postfix | delivers and receives mail | relays for anyone else |
| OpenDKIM | signs | — |
| Webhook Worker | notifies client systems | contacts private addresses or follows redirects |
