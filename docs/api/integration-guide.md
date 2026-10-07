# Catto Mail Smarthost API: client integration guide

This guide explains how a client application integrates with a Catto Mail Smarthost
installation. The normative contract is the OpenAPI document
[`openapi.v1.yaml`](openapi.v1.yaml); where this guide and the contract differ, the
contract wins. An installation serves both documents at `/docs/api`.

Base URL: `https://<your Smarthost host>/v1`. All requests and responses are JSON over
HTTPS; errors are `application/problem+json` (RFC 9457).

## 1. What the four key results mean

| Smarthost says | It means | It does **not** mean |
|---|---|---|
| `remote_accepted` | The recipient's mail server accepted responsibility for the message. | Inbox placement, receipt, or that anyone read it. |
| `open_recorded` | The tracking pixel was fetched (by a person, a mail client, a security scanner or a privacy proxy). | A proven human read. |
| a validation result | Evidence about whether an address can receive mail. | Consent to send to it. Consent is your application's responsibility. |
| your unsubscribe | Your list/newsletter/campaign unsubscribe, handled by your application. | A global suppression. Smarthost never stores ordinary unsubscribes. |

`outcome_unknown` means Smarthost could not determine the final SMTP outcome; it is never a
success.

## 2. Authentication

Send your API key as a bearer token on every request:

```http
Authorization: Bearer shk_...
```

- Keys are created by your client admin in the dashboard (**API keys**) or by the operator.
  A key is shown **once**; Smarthost stores only its hash.
- Keys may have an expiry. Revoked and expired keys, and keys of a closed account, fail
  with `401` exactly like unknown keys.
- **Rotation without downtime:** create a replacement key, deploy it, confirm in the
  dashboard that its *last used* time appears, then revoke the old key.
- Your client identity always comes from the key. Requests never carry a `client_id`, and
  another client's resources answer `404`, exactly like missing ones.

## 3. Account status

| Status | Create work (validation jobs, send jobs, recipients, submit) | Read your resources | Receive webhooks |
|---|---|---|---|
| `pending_approval` | No: `403` | Yes | Yes |
| `active` | Yes, within your limits | Yes | Yes |
| `throttled` | Yes, but sending is paced more slowly and the API accepts fewer requests per minute | Yes | Yes |
| `suspended` | No: `403`; work in progress is paused | Yes | Yes |
| `closed` | No: keys fail with `401` | No | No |

Global opt-out reporting (section 13) follows its own rule: reporting an opt-out works in
every status that authenticates; lifting one needs `active` or `throttled`.

## 4. Idempotency

Every creating request needs an `Idempotency-Key` header (a UUID is recommended):
validation-job creation, send-job creation, each recipient batch, and global opt-out
creation.

- Same key and same body: a replay. No second side effect; the same status code and
  resource, with `Idempotent-Replayed: true`. A replay never counts against a quota again.
- Same key and a different body: `422` (`idempotency-key-reused`).
- Same key while the first request is still running: `409` (`idempotency-in-progress`).
  Retry later with the same key.

Submitting a send job and lifting an opt-out are naturally idempotent.

Retry safely: after a timeout or a `5xx`, repeat the request with the **same** key.

## 5. Errors

```json
{"type": "https://<host>/problems/validation-error", "title": "Validation failed", "status": 422,
 "detail": "The batch was rejected as a whole.",
 "errors": [{"pointer": "/recipients/3/email_address", "message": "Not a usable email address."}]}
```

Act on `status` and the last segment of `type` (the problem slug). Common slugs:
`unauthorized` (401), `forbidden` (403), `not-found` (404), `job-not-collecting` (409),
`idempotency-in-progress` (409), `payload-too-large` (413), `validation-error` (422),
`recipient-limit-exceeded` (422), `idempotency-key-reused` (422), `rate-limited` (429),
`quota-exceeded` (429).

## 6. Limits, rate limits and quotas

| Limit | Value | When exceeded |
|---|---|---|
| Request body | 10 MiB | `413` |
| Addresses per validation job | 10,000 | `422` |
| Recipients per batch | 500 (or lower if configured) | `422` (`batch-too-large`) |
| Recipients per send job | 10,000, or your account's lower limit | `422` (`recipient-limit-exceeded`) |
| Requests per minute | per key, and for all keys of your account together; lower while `throttled` | `429` (`rate-limited`) with `Retry-After` |
| Quotas per UTC day or month | validation jobs, validation addresses, send jobs, recipients, as agreed for your account | `429` (`quota-exceeded`) with `Retry-After` |

A quota refusal happens before any work is created and names the quota:

```json
{"type": "https://<host>/problems/quota-exceeded", "title": "Quota exceeded", "status": 429,
 "detail": "This request would exceed the client's daily send recipients quota of 5000 (4950 used, 100 requested); it resets at 2026-10-08T00:00:00.000000Z.",
 "quota": {"metric": "send_recipients", "period": "day", "limit": 5000, "used": 4950,
           "requested": 100, "resets_at": "2026-10-08T00:00:00.000000Z"}}
```

Quotas count work as it is admitted (jobs created, addresses submitted, recipients
accepted). Your dashboard (**Usage**) shows your limits and today's and this month's
admitted work. Back off on `429` and honour `Retry-After`.

## 7. Pagination

Collections use keyset pagination: `?limit=1..1000` (default 100) and an opaque `cursor`.
Each page returns `pagination.next_cursor`; pass it as `cursor` for the next page. `null`
means there are no more pages. Cursors are opaque; do not build them yourself.

## 8. Validation jobs

```http
POST /v1/validation-jobs
Idempotency-Key: 6f1c...
{"external_reference": "import-2026-10-07",
 "addresses": [{"address": "Ann@Example.com", "external_address_reference": "subscriber-17"}]}
```

- `202` returns the job (status `queued`). Validation is asynchronous.
- Poll `GET /v1/validation-jobs/{id}` until `completed` or `failed`, or subscribe to the
  `validation.completed` / `validation.failed` webhooks.
- Results: `GET /v1/validation-jobs/{id}/addresses` (paginated). Each address has its
  `original_address` (never modified), a normalised form, evidence fields, an
  `overall_classification` and a `confidence`.
- A suggested correction (`suggested_address`) is only a suggestion. Smarthost never
  changes an address.
- Usage: one unit per address that reaches a final result.

## 9. Send jobs: create, batch, submit

1. **Create** the job with job-level metadata only:

   ```http
   POST /v1/send-jobs
   Idempotency-Key: ...
   {"external_reference": "campaign-42", "message_class": "subscription", "list_id": "news.example.com",
    "sender_identity": {"email": "news@example.com", "name": "Example News"},
    "tracking": {"opens": true, "clicks": true}}
   ```

   The sender domain must be a verified sending domain of your account (section 10).
2. **Add recipients** in batches of up to 500 fully rendered recipients while the job is
   `collecting`:

   ```http
   POST /v1/send-jobs/{id}/recipients
   Idempotency-Key: ...
   {"recipients": [{"external_recipient_reference": "subscriber-17", "email_address": "ann@example.com",
     "subject": "October news", "html_body": "<p>Hello Ann</p>", "text_body": "Hello Ann",
     "unsubscribe_url": "https://example.com/u/opaque-token"}]}
   ```

   A batch is accepted or rejected as a whole. Duplicate addresses (after normalisation)
   are rejected within and across batches. Smarthost does no templating: send the final
   subject and body per recipient. `subscription` jobs need `list_id` and an
   `unsubscribe_url` (HTTPS, RFC 8058 one-click, hosted by you) per recipient;
   `transactional` jobs accept neither.
3. **Submit** to seal the recipient set: `POST /v1/send-jobs/{id}/submit`. The job becomes
   `queued`; nothing can be added afterwards.

The job then moves through `processing` and `dispatched` to `completed`, which can take days
while deferred messages are retried. Per-message state:
`GET /v1/send-jobs/{id}/messages`; the append-only history of one message:
`GET /v1/messages/{id}/events`.

**External references** (`external_reference`, `external_recipient_reference`,
`external_address_reference`) are yours: opaque, not required to be unique, and returned
with every resource so you can map Smarthost ids to your records.

## 10. Sending domains and DKIM

Your sender domains are registered by your account's operator or client admin. Each domain
is verified by a DNS TXT record (`_smarthost-verification.<domain>`, value shown in the
dashboard under **Sending domains**). In production a domain also needs active DKIM before
a job using it can be submitted. Your operator gives you the DKIM DNS record to publish.

## 11. Webhooks

Endpoints are managed by your client admin in the dashboard (**Webhooks**): URL (HTTPS),
subscribed events, enable/disable, secret rotation and a test event. There is no API for
managing endpoints.

Each delivery is an HTTPS `POST` of one event:

```json
{"id": "<event id>", "type": "send.completed", "created_at": "2026-10-07T10:00:00.000000Z", "data": { ... }}
```

**Verify the signature before parsing:**

- Header `Smarthost-Signature: t=<unix seconds>,v1=<hex>`. `v1` is HMAC-SHA256 with your
  endpoint's signing secret over `<t>.<raw body bytes>`.
- Compare in constant time, against every `v1` present. During a secret rotation there is
  one per valid secret.
- Reject timestamps more than 300 seconds from your clock.

**De-duplicate on `Smarthost-Event-Id`** (equal to the body's `id`). Delivery is
at-least-once: the same event may arrive more than once, and events may arrive out of
order (use `created_at`). Ignore event types you do not know. Answer `2xx` quickly and do
the work asynchronously.

**Retries:** `408`, `425`, `429`, `5xx` and transport errors are retried with exponential
backoff (honouring `Retry-After` up to the installation maximum) for about a day; other
`4xx` and every `3xx` fail at once. Redirects are never followed. Endpoints are never
disabled automatically; failed deliveries stay visible in your dashboard, including how
many failed since the last success.

**Test:** `POST /v1/webhooks/test` with `{"webhook_endpoint_id": "..."}` queues one
`webhook.test` event for that endpoint (`202`); it is never sent synchronously.

Events: `validation.completed`, `validation.failed`, `send.completed`, `send.failed`,
`message.hard_bounced`, `message.complained`.

## 12. Polling and reconciliation

Webhooks are a convenience, not your source of truth. Reconcile by polling: after a missed
webhook, an outage or at regular intervals, read the job (`GET /v1/send-jobs/{id}`,
`GET /v1/validation-jobs/{id}`) and the message list. Every state is available by polling.

## 13. Global suppression and opt-outs

- Smarthost suppresses an address **for every client** after a recipient-specific hard
  bounce, a complaint or repeated recipient soft bounces. A message to a suppressed address
  gets `current_status` `suppressed` and is never sent.
- Your ordinary unsubscribes stay in your application. Never report them to Smarthost.
- Only when a recipient explicitly asks not to receive email from **any** sender of this
  installation may an account with the operator-granted global-opt-out capability report it:
  `POST /v1/global-suppressions` (idempotent), and later `POST /v1/global-suppressions/{id}/lift`
  if the recipient withdraws it. Lifting removes only your opt-out; other suppressions of the
  address stay.

## 14. Tracking

When enabled per job, Smarthost adds an open pixel to HTML parts and rewrites eligible
HTTP(S) links in HTML parts. Plain text, `mailto:`, fragment-only and unsubscribe links are
never rewritten. Recorded opens and clicks appear as `open_recorded` / `click_recorded`
events. Opens are unreliable signals (section 1).

## 15. Integration checklist

1. Receive your API key from your client admin; store it in your secret store.
2. Verify your sending domain (TXT record) and publish its DKIM record.
3. Configure a webhook endpoint, store its secret, and implement signature verification
   and de-duplication. Send a test event and check that your receiver accepts it.
4. Run a small validation job and read its results.
5. Run a small send job to addresses you own, and follow it to `completed`.
6. Implement polling-based reconciliation and `429` back-off.
