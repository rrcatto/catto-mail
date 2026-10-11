# Client onboarding and SaaS operations

Specification 2.10 (Phase 9, `saas_operations`). This is how an operator takes on, limits,
watches, bills and, if necessary, stops a client. Commands are shown as production console
commands (`smarthostctl prod console …`; in development `smarthostctl console …`). Every
action also has a dashboard form under Clients › Clients › *client*, Clients › Abuse & reputation and
Clients › Usage & billing.

Clients come in two ways:
- **Created by an operator**, reviewed and approved by an operator (sections 1 and 2).
- **Public sign-up**, when the operator enables it (`APP_PUBLIC_ONBOARDING_ENABLED=true`; off by
  default). The new client is activated automatically into a starter tier with low limits
  (owner decision 2026-10-11). See [Public sign-up](#public-sign-up-owner-decision-2026-10-11).

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
- [ ] **Sending domain.** The client sets it up in its own workspace (Settings › Sending
  domains, runbook §6a); no console work is needed.
  - A client admin adds the domain and publishes the TXT challenge, then checks DNS. Its limit
    (section 3) caps the number of domains.
  - Once the domain is verified, the host agent generates its DKIM key. The page shows the DKIM
    and DMARC records to publish, and why SPF needs no change.
  - The key is activated as soon as the DKIM record is in DNS, and the domain shows *Active*.
  - A domain another client has also verified is the exception: set up its key by hand (runbook
    §6, then `smarthost:domain:dkim`).
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
- [ ] **First week:** watch Clients › Abuse & reputation and the client's reputation figures; seed-test
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
- Quotas, usage summaries and billing statements use the calendar days and months of the
  installation's time zone (`SMARTHOST_TIMEZONE`, Africa/Johannesburg by default).
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

- Client admins manage their own keys in the dashboard (in the client workspace: Settings › API keys). Operators with
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
3. Acknowledge with what you did: Clients › Abuse & reputation, or
   `smarthost:reputation acknowledge <alert-id> --operator <you> --note "…"`.

The default thresholds are starting points. Calibrate them on real traffic.

## 6. Usage, reconciliation and billing statements

```sh
smarthost:usage:summary --period current_month              # every client
smarthost:usage:summary --client <id> --month 2026-09     # daily figures: Clients › Usage & billing › client
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

## Public sign-up (owner decision 2026-10-11)

**The decision.** Members of the public may open an account and start sending without an
operator running commands or approving them one by one. A new client is activated automatically
into a **starter tier** with low limits. The operator is notified, and can throttle, suspend or
close any client as usual (section 2). This replaces the earlier rule that approval would stay
manual. Operator-created clients are still reviewed and approved by hand.

**Turning it on.** Set `APP_PUBLIC_ONBOARDING_ENABLED=true` in `infra/.env` (group *Public
sign-up*), then `smarthostctl prod check` and `prod recreate`. While it is `false` (the default),
the sign-up page does not exist (404), and the front page and the sign-in page do not offer it.

**What a person does.**
1. On `/dashboard/sign-up` (linked from the front page and the sign-in page) they give the
   organisation's name and their email address.
2. A confirmation link arrives by email. It is the same single-use passwordless link as for
   signing in, valid for `APP_LOGIN_LINK_TTL_SECONDS`. Nothing is created until it is used.
3. The link's page shows what will be created, and, when `APP_ACCEPTABLE_USE_POLICY_VERSION` is
   set, asks them to accept that policy version. Opening the account:
   - creates the client through the gated entry point for public applications (origin
     *public sign-up*, plan `starter`);
   - makes the person its client admin;
   - records the policy acceptance;
   - sets the starter limits;
   - activates the client;
   - signs them in. Every step is audited (actor `public-sign-up`).
4. The client's overview then shows the next steps: add a sending domain (they set it up
   themselves, DKIM included), create an API key, add a webhook.

**The starter tier** (System › Settings, group *Public sign-up*, or `infra/.env`). It is applied
when an account is opened, never above the installation ceilings. Later changes do not touch
existing clients: raise or lower an existing client's limits on its page (section 3). The
defaults:

| Setting | Limit | Default |
|---|---|---|
| `APP_STARTER_SEND_RECIPIENTS_PER_DAY` | `send_recipients_per_day` | 200 |
| `APP_STARTER_SEND_RECIPIENTS_PER_MONTH` | `send_recipients_per_month` | 3,000 |
| `APP_STARTER_VALIDATION_ADDRESSES_PER_DAY` | `validation_addresses_per_day` | 1,000 |
| `APP_STARTER_API_KEY_LIMIT` | `max_api_keys` | 2 |
| `APP_STARTER_WEBHOOK_ENDPOINT_LIMIT` | `max_webhook_endpoints` | 2 |
| `APP_STARTER_SENDING_DOMAIN_LIMIT` | `max_sending_domains` | 2 |

There is no automatic promotion out of the starter tier: an operator raises limits per client.

**Abuse protection** (no third-party service):
- The form accepts at most 10 submissions an hour per client IP. Each address gets at most 3
  confirmation links an hour.
- The confirmation page and its confirmation share 20 requests an hour per client IP; at most 5
  confirmations an hour are accepted per address.
- A honeypot field that people never see: a form robot that fills it in is answered like a
  success, and nothing is sent.
- The answer never says whether an address already has an account. An address that already
  belongs to a user or to a client's contact gets no sign-up link; a user gets an ordinary
  sign-in link instead.
- Every form is a CSRF-protected POST; the link's page (a GET) changes nothing; tokens are
  stored only as hashes and never logged.
- A new client still cannot send until its own domain is verified and, in production, has an
  active DKIM key, and the starter limits cap what it can send.

**What the operator sees.**
- An email to `APP_ADMIN_EMAIL` for every sign-up, with a link to the client's page.
- Clients › Clients: the tab *Public sign-ups* (and an *Origin* filter), newest first, with
  the time each one signed up.
- The Clients button's badge and *Needs attention* on the overview count the sign-ups of the
  last 7 days.
- Review new clients as you would any other (section 1). Throttle, suspend or close one that
  misuses the service (section 2), and watch Clients › Abuse & reputation (section 5).

**Before turning it on**, have evidence for each of these:

- [ ] **Stability:** several weeks of production without unplanned delivery outages; the
      upgrade and rollback procedures (runbook §18) exercised at least once.
- [ ] **Reputation:** hard-bounce, complaint and deferral rates of the existing clients
      steadily below the warning thresholds; no blocklisting; feedback loops and postmaster
      tools registered.
- [ ] **Bounce and complaint handling:** proven with live traffic (DSNs and ARF reports
      processed, suppressions created, alerts raised and handled).
- [ ] **Backups:** off-host, encrypted, scheduled, and one restore rehearsed with recent data.
- [ ] **Abuse procedures:** who watches the sign-up notifications and the alerts, and how often;
      response times; when to throttle, suspend and close; how to handle abuse reports and legal
      requests.
- [ ] **Starter tier:** limits you are comfortable letting anyone use without review.
- [ ] **Commercial terms:** plans and their limits, pricing, the billing system the statements
      feed, payment collection and dunning (catto-mail has no billing provider).
- [ ] **Service policy:** the published policy text and its version
      (`APP_ACCEPTABLE_USE_POLICY_VERSION`).
- [ ] **Retention:** decided periods for each data category (runbook §16).
- [ ] **Capacity:** the measured query plans (`phase9-query-plans.txt`) and the host's
      resources against the expected number of clients.
