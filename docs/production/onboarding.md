# Client onboarding and SaaS operations

Specification 2.10 (Phase 9, `saas_operations`). This is how an operator takes on, limits,
watches, bills and, if necessary, stops a client. Commands are shown as production console
commands (`smarthostctl prod console …`; in development `smarthostctl console …`). Every
action also has a dashboard form under Operator › Clients › *client*, Operator › Alerts and
Operator › Usage.

Public self-service onboarding is **disabled** (`APP_PUBLIC_ONBOARDING_ENABLED=false`), and no
public registration route exists. Every client is created and approved by an operator. See
[Opening public onboarding](#opening-public-onboarding-a-later-owner-decision) for the decision
that would change this.

## 1. Checklist for a new client

Before approval:

- [ ] **Identity and purpose.**
  - The organisation, and what it sends: transactional or subscription mail, its volumes and
    its audience.
  - How its recipients opted in.
  - Validation is not consent: a valid address is not permission to mail it.
- [ ] **Contacts recorded.**
  - Operational contact (`--contact-email`), billing contact and abuse contact.
  - The abuse contact must answer complaints quickly.
- [ ] **Service policy.**
  - When `APP_ACCEPTABLE_USE_POLICY_VERSION` is set, a client admin accepts that version in the
    dashboard.
  - Or you record an acceptance obtained elsewhere, with a reference
    (`smarthost:client:account <id> policy-accept <version> --reference …`).
  - A client that requires acceptance cannot be approved without it.
- [ ] **Limits agreed and set** (section 3). Start low; raise as the reputation record grows.
- [ ] **Sending domain.**
  - The client adds its domain and publishes the TXT challenge.
  - Once the domain is verified, you generate its DKIM key and the client publishes the DKIM,
    SPF and DMARC records (runbook §6).
- [ ] **Unsubscribe handling understood.**
  - An ordinary unsubscribe is the client's own subscription state; the client stops sending to
    that address.
  - Smarthost global suppressions are for bounces, complaints and recipient global opt-outs only
    (integration guide, *Suppressions*).
- [ ] **Webhooks.**
  - The client verifies signatures, de-duplicates by event id and polls as a fallback.
  - Delivery is at-least-once.
- [ ] **Client users invited**, with the client role each needs (admin or viewer).
- [ ] **API key issued** with a name and, preferably, an expiry. The secret is shown once.
- [ ] **Approval** with a reason (section 2).
- [ ] **First week:** watch Operator › Alerts and the client's reputation figures; seed-test
      first sends where possible.

The client's own guide is public at `https://<host>/docs/api` (the integration guide and the
OpenAPI contract).

## 2. Lifecycle

```sh
smarthost:client:create --company "Example Ltd" --contact-email ops@example.net \
  --abuse-contact-email abuse@example.net --billing-contact-email billing@example.net
# -> pending_approval; policy acceptance required (unless --policy-acceptance exempt)

smarthost:client:set-status <id> active    --operator <you> --note "reviewed; policy 2026-10 accepted"
smarthost:client:set-status <id> throttled --operator <you> --note "hard bounces 4 % (alert …)"
smarthost:client:set-status <id> suspended --operator <you> --note "complaint spike; client contacted"
smarthost:client:set-status <id> active    --operator <you> --note "list cleaned, resumed"
smarthost:client:set-status <id> closed    --operator <you> --note "contract ended"
```

| From | Allowed to | Permission |
|---|---|---|
| `pending_approval` | `active` (approve), `closed` (reject) | `PLATFORM.CLIENT.APPROVE` |
| `active` | `throttled`, `suspended` | `PLATFORM.CLIENT.RESTRICT` |
| `throttled` | `active`, `suspended` | `PLATFORM.CLIENT.RESTRICT` |
| `suspended` | `active`, `throttled` | `PLATFORM.CLIENT.APPROVE` |
| any but `closed` | `closed` | `PLATFORM.CLIENT.APPROVE` |

What each state does:

| State | API | Delivery and validation | Webhooks |
|---|---|---|---|
| `pending_approval` | sign-in and reads; work refused (403) | none | none |
| `active` | full, within limits | normal | normal |
| `throttled` | full within quotas; API rate capped at `APP_THROTTLED_CLIENT_API_RATE_PER_MINUTE` | message starts paced at `DELIVERY_THROTTLED_CLIENT_RATE_PER_MINUTE` | normal |
| `suspended` | reads only; work refused (403) | running send jobs lose their lease at the next renewal; nothing new starts; validation stops; queued work waits | events about past work still delivered |
| `closed` | keys no longer authenticate | none | none |

Notes:

- Suspension is the per-client kill switch. It does not recall mail Postfix has already
  accepted: to hold that too, use the installation-wide pause (runbook §15).
- Every change needs a reason (3–1000 characters), which the audit log keeps.
- `closed` is final, and nothing returns to `pending_approval`.

## 3. Limits and quotas

```sh
smarthost:client:limits <id>                      # show limits, ceilings and current usage
smarthost:client:limits <id> --set send_recipients_per_day=5000 --set max_api_keys=3 \
  --operator <you> --note "plan S agreed 2026-10-07"
smarthost:client:limits <id> --clear send_recipients_per_day --operator <you> --note "…"
```

| Limit | Kind | Installation ceiling |
|---|---|---|
| `api_requests_per_minute` | rate, per client (every key together) | `API_RATE_LIMIT_PER_MINUTE` |
| `validation_jobs_per_day`, `validation_addresses_per_day`, `validation_addresses_per_month` | quota | none |
| `send_jobs_per_day`, `send_recipients_per_day`, `send_recipients_per_month` | quota (recipients counted when added) | none |
| `max_recipients_per_send_job` | per job | the API maximum (10,000) |
| `max_api_keys`, `max_webhook_endpoints`, `max_sending_domains` | count | `APP_CLIENT_API_KEY_LIMIT`, `APP_CLIENT_WEBHOOK_ENDPOINT_LIMIT`, `APP_CLIENT_SENDING_DOMAIN_LIMIT` |

How they behave:

- Empty means no client-specific limit; the ceiling still applies.
- Quotas use UTC days and months.
- A refused request is `429 quota-exceeded` with `Retry-After` (seconds until the period
  resets) and the client's own figures. Concurrent requests cannot exceed a quota together.
- Lowering a limit below current usage refuses new work until the period resets; nothing
  admitted is undone.

## 4. API keys

```sh
smarthost:api-key:create <id> --name "production server" --expires 2027-01-31
smarthost:api-key:list <id>
smarthost:api-key:revoke <key-id>
```

- Client admins manage their own keys in the dashboard (Client › API keys). Operators with
  `PLATFORM.CLIENT_KEY.MANAGE` manage any client's keys.
- The secret is shown once, never stored, and cannot be recovered.
- Rotation: create a new key, deploy it, confirm its *last used* time, then revoke the old one.

## 5. Reputation alerts

`<instance>-reputation-evaluate.timer` runs `smarthost:reputation evaluate` every 15 minutes.
For each client and window (24 hours and 7 days) it records messages submitted, validation
addresses, hard and soft bounces, deferrals, provider-policy failures, complaints, suppressions
and unknown outcomes. It raises alerts:

| Metric | Default warning / critical | Variable |
|---|---|---|
| hard-bounce rate | 2 % / 5 % | `APP_REPUTATION_HARD_BOUNCE_*_PERCENT` |
| complaint rate | 0.1 % / 0.3 % | `APP_REPUTATION_COMPLAINT_*_PERCENT` |
| deferral rate | 15 % / 30 % | `APP_REPUTATION_DEFERRAL_*_PERCENT` |
| volume increase (24 h against the 7-day daily average) | 3× / 10× | `APP_REPUTATION_VOLUME_INCREASE_*_FACTOR` |

How alerts work:

- Rates need at least `APP_REPUTATION_MIN_MESSAGES` messages in the window.
- Every alert shows its numerator and denominator.
- An alert resolves itself when the metric recovers.
- An escalation from warning to critical clears its acknowledgement.

**Alerts never act on a client.** On an alert:

1. Look at the client's page (reputation, recent send jobs, bounce and complaint details).
2. Decide: no action, contact the client, throttle or suspend (section 2, with the alert in
   the reason).
3. Acknowledge with what you did: Operator › Alerts, or
   `smarthost:reputation acknowledge <alert-id> --operator <you> --note "…"`.

The default thresholds are starting points. Calibrate them on real traffic.

## 6. Usage, reconciliation and billing statements

```sh
smarthost:usage:summary --period current_month              # every client
smarthost:usage:summary --client <id> --month 2026-09     # daily figures: Operator › Usage › client
smarthost:usage:reconcile --period previous_month           # exit 1 when inconsistent
smarthost:usage:export <id> --period previous_month --format csv --operator <you> > usage.csv
smarthost:billing:statement prepare --period previous_month --operator <you>
smarthost:billing:statement list --period previous_month
smarthost:billing:statement finalize <statement-id> --operator <you>
smarthost:billing:statement mark-exported <statement-id> --reference INV-2026-0042 --operator <you>
smarthost:billing:statement void <statement-id> --note "…" --operator <you>
```

**Units.**
- `validation_address`: addresses of a validation job, written by the validator.
- `message_submitted`: one per message accepted by Postfix, written by the delivery daemon; at
  most one per message.

**Monthly close.** After the month ends:
1. Reconcile.
2. Prepare the statements (drafts; preparing again updates a draft).
3. Finalize. Finalizing reconciles again and refuses if anything is inconsistent or the totals
   changed since preparation.
4. Use the export in your billing system.
5. Mark each statement exported with that system's reference.

Corrections: void the statement (with a reason) and prepare it again.

**Billing boundary.**
- Smarthost holds quantities only: no prices, plans, currencies, taxes, invoices or payment
  data.
- No billing provider is connected.
- Exports are deterministic (the same period gives the same document), and every export is
  audited.

**Reconciliation findings** and what they mean:

| Finding | Meaning |
|---|---|
| `validation_usage_mismatch` | a validation job's metered addresses differ from its completed addresses |
| `validation_usage_without_job`, `validation_usage_wrong_client` | validation usage that references no job, or another client's job |
| `usage_wrong_reference` | a usage record with an unexpected reference type |
| `message_usage_invalid` | a message unit for a message that does not exist, belongs to another client, or was never accepted |
| `message_without_usage` | a message accepted by Postfix in the period without its unit |

Investigate before finalizing. A finding usually means a manual database change or a bug, and
both should be reported.

## 7. Account details and notes

```sh
smarthost:client:account <id> note "Contract signed; abuse contact verified by phone." --operator <you>
smarthost:client:account <id> policy-accept 2026-10 --reference "signed order form 2026-10-07" --operator <you>
smarthost:client:account <id> policy-require --operator <you> --note "…"
smarthost:client:account <id> policy-exempt  --operator <you> --note "internal client"
```

- Private notes are append-only and visible only to operators (`PLATFORM.CLIENT.VIEW`), never
  to the client.
- Contact details are edited on the client page (Account).

## 8. Permissions

The OPERATOR role holds every Phase 9 permission. A custom role can hold any subset.

| Permission | Allows |
|---|---|
| `PLATFORM.CLIENT.APPROVE` | approve, reject, reactivate a suspended client, close |
| `PLATFORM.CLIENT.RESTRICT` | throttle, suspend, unthrottle |
| `PLATFORM.CLIENT.MANAGE` | create clients, account details, notes, policy acceptance |
| `PLATFORM.CLIENT_LIMIT.MANAGE` | set limits |
| `PLATFORM.CLIENT_KEY.MANAGE` | create and revoke any client's API keys |
| `PLATFORM.USAGE.VIEW` | usage summaries and reconciliation |
| `PLATFORM.USAGE.EXPORT` | exports and billing statements |
| `PLATFORM.ABUSE.VIEW` | alerts and reputation figures |
| `PLATFORM.ABUSE.MANAGE` | run the evaluation, acknowledge alerts |

## Opening public onboarding (a later owner decision)

`APP_PUBLIC_ONBOARDING_ENABLED=true` alone opens nothing: no public route exists.

Opening public onboarding would need, in a later reviewed change:
- a registration workflow that calls the single gated entry point, which can only create a
  `pending_approval` client of origin `public_application`;
- abuse protection on that form (rate limits, challenge, verified email).

Approval would stay manual.

Decide it only with evidence for each of these:

- [ ] **Stability:** several weeks of production without unplanned delivery outages; the
      upgrade and rollback procedures (runbook §18) exercised at least once.
- [ ] **Reputation:** hard-bounce, complaint and deferral rates of the existing clients
      steadily below the warning thresholds; no blocklisting; feedback loops and postmaster
      tools registered.
- [ ] **Bounce and complaint handling:** proven with live traffic (DSNs and ARF reports
      processed, suppressions created, alerts raised and handled).
- [ ] **Backups:** off-host, encrypted, scheduled, and one restore rehearsed with recent data.
- [ ] **Abuse procedures:** who watches alerts and how often; response times; when to throttle,
      suspend and close; how to handle abuse reports and legal requests.
- [ ] **Commercial terms:** plans and their limits, pricing, the billing system the statements
      feed, payment collection and dunning.
- [ ] **Service policy:** the published policy text and its version
      (`APP_ACCEPTABLE_USE_POLICY_VERSION`).
- [ ] **Retention:** decided periods for each data category (runbook §16).
- [ ] **Capacity:** the measured query plans (`phase9-query-plans.txt`) and the host's
      resources against the expected number of clients.
