# Catto Mail Smarthost API: client integration guide

This guide explains how a client application integrates with a Catto Mail Smarthost
installation. The normative contract is the OpenAPI document
[`openapi.v1.yaml`](openapi.v1.yaml); where this guide and the contract differ, the
contract wins. An installation serves both documents at `/docs/api`.

Base URL: `https://<your Smarthost host>/v1`. All requests and responses are JSON over
HTTPS; errors are `application/problem+json` (RFC 9457). Timestamps are RFC 3339 in the
installation's time zone with its offset (South African Standard Time by default, for example
`2026-10-10T10:48:56.123456+02:00`); parse them as RFC 3339, never as a fixed `Z` suffix.

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

Your account is created by the operator of the installation, or, where the installation offers
it, by signing up at `https://<your Smarthost host>/dashboard/sign-up` (a new account starts with
low limits that the operator can raise). Your client admin then manages keys, sending domains and
webhook endpoints in the dashboard.

An application can also get its API key and webhook endpoint without anyone copying them: it
sends your client admin to Smarthost to approve the connection, and collects its credentials
itself (section 15).

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
- One operation takes no key: `POST /v1/connect/exchange`, with which an application that is
  being connected collects its key (section 15).

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
`quota-exceeded` (429), and for connecting an application `invalid-connect-code` (400) and
`connect-refused` (409).

## 6. Limits, rate limits and quotas

| Limit | Value | When exceeded |
|---|---|---|
| Request body | 10 MiB | `413` |
| Addresses per validation job | 10,000 | `422` |
| Recipients per batch | 500 (or lower if configured) | `422` (`batch-too-large`) |
| Recipients per send job | 10,000, or your account's lower limit | `422` (`recipient-limit-exceeded`) |
| Requests per minute | per key, and for all keys of your account together; lower while `throttled` | `429` (`rate-limited`) with `Retry-After` |
| Quotas per calendar day or month in the installation's time zone (Africa/Johannesburg by default; the problem's `resets_at` says when) | validation jobs, validation addresses, send jobs, recipients, as agreed for your account | `429` (`quota-exceeded`) with `Retry-After` |

A quota refusal happens before any work is created and names the quota:

```json
{"type": "https://<host>/problems/quota-exceeded", "title": "Quota exceeded", "status": 429,
 "detail": "This request would exceed the client's daily send recipients quota of 5000 (4950 used, 100 requested); it resets at 2026-10-08T00:00:00.000000+02:00.",
 "quota": {"metric": "send_recipients", "period": "day", "limit": 5000, "used": 4950,
           "requested": 100, "resets_at": "2026-10-08T00:00:00.000000+02:00"}}
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

Your client admin adds your sender domains in the dashboard (**Settings › Sending domains**).
Each domain is verified by a DNS TXT record (`_smarthost-verification.<domain>`; the page shows
the value). Once it is verified, the page shows the domain's DKIM record to publish (and a DMARC
record to start with), and the key becomes active as soon as the record is in DNS. In production
a job can be submitted only from a domain whose DKIM key is active. Until then, submitting
answers `422 submit-rejected` with the error "The sending domain has no active DKIM
configuration."; nothing is sent unsigned.

**Check before you send:** `GET /v1/sending-domains` lists your domains, so that your
application can warn its user before it sends from one that cannot send yet:

```json
{"data": [{"id": "0199…", "domain": "example.com", "status": "verified", "dkim_status": "active",
           "verified_at": "2026-10-07T10:00:00.000000+02:00", "can_send": true}]}
```

- `status` is `pending` (the TXT record was not found yet), `verified` or `disabled`.
- `dkim_status` is `not_configured`, `pending_dns`, `active` or `disabled`.
- `can_send` is the submit rule applied to the domain as it is now. While it is `false` you can
  still create a job and add recipients; only the submit is refused.
- The list is read-only and not paginated (an account has few domains), ordered by domain.

## 11. Webhooks

Endpoints are managed by your client admin in the dashboard (**Webhooks**): URL (HTTPS),
subscribed events, enable/disable, secret rotation and a test event. There is no API for
managing endpoints.

Each delivery is an HTTPS `POST` of one event:

```json
{"id": "<event id>", "type": "send.completed", "created_at": "2026-10-07T10:00:00.000000+02:00", "data": { ... }}
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
`message.hard_bounced`, `message.complained`. Two more exist: `webhook.test` (the test above;
it needs no subscription) and `repermission.responded` (a recipient's answer to a
re-permission message that the operator sent for you from an address batch; its `data` is the
contract's `RepermissionWebhookData`). An endpoint created by connecting an application
(section 15) is subscribed to the first six.

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

## 15. Connecting an application with one approval

Instead of a client admin copying an API key and a webhook secret into your application, your
application can ask for them: an authorisation-code flow with PKCE (as in OAuth 2, RFC 7636,
`S256` only). Your application needs no registration beforehand. It needs to know the
installation's address (`https://<your Smarthost host>`), which its user types in once.

**1. Send the person's browser to the connect page.** Create a random `state` and a random
code verifier (43 to 128 characters of `A-Z a-z 0-9 - . _ ~`), keep both on your server (in
the person's session), and redirect to:

```text
https://<your Smarthost host>/dashboard/connect
    ?application_name=Example%20Lists
    &redirect_uri=https%3A%2F%2Flists.example.com%2Fcattomail%2Fcallback
    &webhook_url=https%3A%2F%2Flists.example.com%2Fcattomail%2Fwebhook
    &state=6f1c0b…
    &code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM
    &code_challenge_method=S256
```

| Parameter | Value |
|---|---|
| `application_name` | 1 to 100 characters. The person sees it, and the API key is named after it. |
| `redirect_uri` | Where the person is sent back to: an absolute `https` URL of your application, at most 2000 characters, without credentials or a fragment. |
| `webhook_url` | Where your application receives its webhooks: an absolute `https` URL, at most 2000 characters, that resolves to public addresses (as for every endpoint, section 11). |
| `state` | 1 to 512 printable ASCII characters without spaces. It comes back unchanged; check it. |
| `code_challenge` | `BASE64URL(SHA-256(code verifier))` without `=` padding: 43 characters. |
| `code_challenge_method` | `S256`. |

Plain `http` URLs are accepted only by a development installation. If a parameter is missing or
refused, the page says which one and the person is **not** sent back to you.

**2. The person signs in and approves.** They sign in to Smarthost, or, where the installation
offers sign-up, open an account there and then; either way they return to the connect step.
They choose the workspace (client) to connect and press *Connect*. Only a client admin can
approve, and only for an active account. If the account has no room for another API key or
webhook endpoint, the page says so and nothing happens.

**3. The person comes back to your `redirect_uri`** with `303 See Other`:

```text
https://lists.example.com/cattomail/callback?code=<single-use code>&state=6f1c0b…
https://lists.example.com/cattomail/callback?error=access_denied&error_description=…&state=6f1c0b…   (declined)
```

Compare `state` with the one you kept; refuse anything else. The code works once and expires
10 minutes after the approval. It is the only thing in the address: no key and no secret.

**4. Exchange the code, from your server.** This is the one request without an API key:

```http
POST /v1/connect/exchange
Content-Type: application/json

{"code": "<the code>", "code_verifier": "<your code verifier>",
 "redirect_uri": "https://lists.example.com/cattomail/callback"}
```

```json
{"connection_id": "0199…", "application_name": "Example Lists", "client_name": "Example Ltd",
 "api_base_url": "https://<your Smarthost host>/v1",
 "api_key": "shk_…",
 "webhook": {"endpoint_id": "0199…", "url": "https://lists.example.com/cattomail/webhook",
             "signing_secret": "whsec_…",
             "events": ["message.complained", "message.hard_bounced", "send.completed",
                        "send.failed", "validation.completed", "validation.failed"]},
 "sending_domains": [{"id": "0199…", "domain": "example.com", "status": "verified",
                      "dkim_status": "active", "verified_at": "2026-10-07T10:00:00.000000+02:00",
                      "can_send": true}]}
```

- **Store `api_key` and `webhook.signing_secret` at once.** They are shown this one time:
  Smarthost keeps only a hash of the key. If the answer is lost, start again from step 1.
- The API key and the webhook endpoint are created by this request, within the account's limits.
  `webhook.endpoint_id` is the `webhook_endpoint_id` for `POST /v1/webhooks/test`.
- `400 invalid-connect-code`: the code is unknown, already used, expired or revoked, or the code
  verifier or `redirect_uri` does not match. The answer is the same for all of these.
- `409 connect-refused`: the code is good, but the account is no longer active or a limit was
  reached since the approval. Nothing was created; the same code works once that is put right,
  until it expires.
- `429 rate-limited`: the exchange is limited per client address; honour `Retry-After`.
- Connecting again with the same `webhook_url` replaces the earlier connection: at this
  exchange its key is revoked and its endpoint removed. Until then the earlier key keeps working.
- A connection never includes global opt-out reporting (section 13); only the operator grants it.

**5. Disconnect.** Your application ends its own connection with the key it received:

```http
DELETE /v1/connection
Authorization: Bearer shk_…
```

`204`: the key is revoked (it now answers `401`) and the webhook endpoint is removed. `404` when
the key was not issued by a connection (a key created in the dashboard belongs to none). The
client admin can also end a connection in the dashboard (**Settings › Connected applications**);
your application then gets `401` and should offer to connect again.

## 16. Integration checklist

1. Get your API key and webhook signing secret: by connecting your application (section 15), or
   from your client admin (**API keys** and **Webhooks**). Store them in your secret store.
2. Add and verify your sending domain (TXT record) and publish its DKIM record; wait until the
   dashboard, or `GET /v1/sending-domains`, shows that it can send.
3. Implement webhook signature verification and de-duplication. Send a test event and check that
   your receiver accepts it.
4. Run a small validation job and read its results.
5. Run a small send job to addresses you own, and follow it to `completed`.
6. Implement polling-based reconciliation and `429` back-off.
