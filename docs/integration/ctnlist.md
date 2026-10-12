# Integrating ctnlist with catto-mail

Specification 2.12. This describes the integration of the ctnlist mailing-list application with
catto-mail: what each side owns, the API calls and webhook events, what ctnlist already does,
and the **ctnlist-side work that is still outstanding**. The ctnlist repository
(`github.com/rrcatto/ctnlist`) is maintained separately, with its own instructions, tests and
releases.

## Responsibilities

```text
ctnlist     = subscribers, lists, memberships (confirmed / unsubscribed), campaigns, content,
              ordinary unsubscribes, consent records — the mailing-list and business state

catto-mail  = validation, sending, suppression, delivery events, bounces, complaints,
              tracking, mail infrastructure (DNS identity, DKIM, Postfix, reputation)
```

They never share a database. ctnlist uses catto-mail only through the authenticated API
(`/v1`, an API key of the ctnlist client) and receives signed webhooks.

## What ctnlist already does (read from its repository, after release 6.0.10)

| Concern | catto-mail API / event | ctnlist |
|---|---|---|
| Validate a list's addresses | `POST /v1/validation-jobs`, `GET /v1/validation-jobs/{id}`, `GET /v1/validation-jobs/{id}/addresses` | `AddressValidation`, `CattoMailClient` |
| Send a campaign | `POST /v1/send-jobs`, `POST /v1/send-jobs/{id}/recipients` (≤ 500 per batch), `POST /v1/send-jobs/{id}/submit` | `CattoMailSender`, `OutgoingMessageFactory` (List-Id `Name <shortcode.domain>`, per-recipient unsubscribe URL) |
| Follow results | `GET /v1/send-jobs/{id}`, `GET /v1/send-jobs/{id}/messages`, `GET /v1/messages/{id}/events` | `SendJobSync` (polling fallback) |
| Recipient global opt-out | `POST /v1/global-suppressions`, `GET /v1/global-suppressions/{id}`, `POST /v1/global-suppressions/{id}/lift` | `GlobalOptOut` |
| Connect and disconnect | the connect page `/dashboard/connect`, `POST /v1/connect/exchange`, `DELETE /v1/connection` | `ConnectFlow`, `ConnectionStore`, `Admin\DeliveryConnectionController` (Delivery page) |
| Whether its From domain can send | `GET /v1/sending-domains` (`can_send`), and `sending_domains` in the exchange answer | `SendingDomainCheck` (a warning on the Delivery page) |
| Webhooks | `validation.completed/failed`, `send.completed/failed`, `message.hard_bounced`, `message.complained`, `webhook.test`; signature `Smarthost-Signature`, de-duplication by `Smarthost-Event-Id` | `WebhookProcessor`, `WebhookSignature`; unknown event types are recorded and ignored |

Ordinary list unsubscribes stay in ctnlist (its unsubscribe links), as catto-mail requires: they
are never reported to catto-mail as global opt-outs.

ctnlist tests this against its own hand-written fake of the `/v1` API, which follows OpenAPI
1.0.0-draft.10. The two development stacks have been run together (2026-10-12, both on one
workstation): connect with ctnlist's button, a proof and a validation job (`bin/dev
test-cattomail`), the signed `send.completed` and `validation.completed` webhooks, a declined
request, disconnect and reconnect. Where ctnlist is behind the contract is listed under
*Outstanding ctnlist work*. Neither application is in production.

## Connecting ctnlist with one approval (both sides implemented, specification 2.12)

An administrator of ctnlist no longer copies catto-mail's API key and webhook signing secret into
ctnlist's `.env` (`CATTOMAIL_API_KEY`, `CATTOMAIL_WEBHOOK_SECRET`; that still works, and a value
there wins). They enter catto-mail's address on ctnlist's Delivery page and press Connect: an
authorisation-code flow with PKCE (integration guide, section 15):

1. ctnlist sends its administrator's browser to `https://<catto-mail>/dashboard/connect` with
   `application_name`, `redirect_uri` (a ctnlist route), `webhook_url`
   (`<APP_BASE_URL>cattomail/webhook`), `state`, `code_challenge` and
   `code_challenge_method=S256`.
2. The administrator signs in to catto-mail (or opens an account, where public sign-up is on),
   chooses the client workspace and approves.
3. catto-mail redirects back with `code` and `state`; ctnlist exchanges the code with
   `POST /v1/connect/exchange` and receives the API key, the webhook signing secret, the API
   base URL and the client's sending domains, once.
4. ctnlist stores the API base URL, and the key and the secret encrypted, in its Settings store.
   Its Disconnect ends the connection with `DELETE /v1/connection` and then clears them, and it
   reads `GET /v1/sending-domains` (`can_send`) to warn when the domain of its From address
   cannot send.

What ctnlist relies on beyond the schemas: `409` from the exchange means "refused for now, the
code stays usable" (it lets the administrator finish again), every other `4xx` means "start
again"; `401` from `DELETE /v1/connection` means the connection is already ended. ctnlist is
named in catto-mail by its list name (`application_name`), and connecting again from the same
site replaces the earlier connection, because the webhook address is the same.

The webhook endpoint this creates is subscribed to the six events ctnlist handles today, not to
`repermission.responded`: a client admin adds that event in catto-mail (Settings › Webhooks)
once ctnlist handles it.

## Re-permission: the catto-mail side (implemented)

1. An administrator exports the old list from ctnlist (CSV with an `email` column) and uploads it
   in catto-mail as an **address batch** of the ctnlist client, purpose *Re-permission*, with the
   list identifier of the ctnlist list: the part inside the angle brackets of its List-Id, e.g.
   `news.example.org` for `News <news.example.org>`.
2. catto-mail validates it (ordinary validation jobs of the ctnlist client; their external
   reference starts with `address-batch:`), the administrator reviews it, records the compliance
   approval and sends in stages. Each message is `subscription` class with List-Id
   `<news.example.org>` and the recipient's own answer links (`/p/<token>`).
3. Each answer is sent to ctnlist's webhook endpoints as **`repermission.responded`**:

```json
{
  "id": "0199…", "type": "repermission.responded", "created_at": "2026-10-07T11:19:00.000000+02:00",
  "data": {
    "batch": {"id": "0199…", "name": "2019 newsletter list", "list_id": "news.example.org"},
    "entry_id": "0199…",
    "address": "Reader@example.org",
    "response": "confirmed",
    "responded_at": "2026-10-07T11:18:58.000000+02:00"
  }
}
```

`response` is one of:

| Response | Meaning | catto-mail already did |
|---|---|---|
| `confirmed` | The person wants to stay subscribed to this list. | nothing else |
| `unsubscribed` | The person leaves this list (also a mail program's one-click unsubscribe). | nothing else: it is ctnlist's unsubscribe |
| `global_opt_out` | The person wants no mail through catto-mail at all. | a global `recipient_global_opt_out` suppression, reported for the ctnlist client |

Answers can change (confirmed ↔ unsubscribed) until a global opt-out, which is final. The payload
carries the answer current at delivery time: apply the newest `responded_at` and ignore older
ones. Delivery is at least once: de-duplicate by the event id, as for every event.

## Outstanding ctnlist work

### Contract 1.0.0-draft.8 to draft.10

1. **`429 quota-exceeded`** is handled like a rate limit: ctnlist retries in place and then on
   every worker pass until the quota resets. It should stop retrying until the problem's
   `quota.resets_at` and tell the administrator which quota was reached.
2. **Limits below the contract ceilings.** ctnlist assumes 10,000 recipients per send job and
   500 per batch. A client's `max_recipients_per_send_job` or the installation's
   `APP_SEND_JOB_MAX_RECIPIENTS_PER_BATCH` can be lower; the batch that passes the limit is
   refused (`422`, `recipient-limit-exceeded` or `batch-too-large`) and ctnlist marks the whole
   job refused.

### Re-permission

1. **Subscribe the endpoint to the event.** In catto-mail, a ctnlist client admin adds
   `repermission.responded` to the ctnlist webhook endpoint's events (client workspace: Settings › Webhooks).
2. **Handle `repermission.responded` in `WebhookProcessor`** (today it is "ignored unknown event
   type"):
   - find the subscriber by `data.address` (catto-mail normalises only the domain to lower
     case; compare the local part exactly, the domain case-insensitively);
   - find the membership of the list whose List-Id identifier equals `data.batch.list_id`;
   - `confirmed` → membership confirmed, not unsubscribed; record the consent evidence (time =
     `responded_at`, source "catto-mail re-permission", batch id, entry id);
   - `unsubscribed` → membership unsubscribed (the same as ctnlist's own unsubscribe);
   - `global_opt_out` → unsubscribe every membership and mark the subscriber "do not contact";
     do **not** call the opt-out API again (catto-mail already holds the suppression);
   - apply only if `responded_at` is newer than the last applied answer for that membership;
   - unknown address or list: record and ignore.
3. **Ignore batch validation webhooks.** `validation.completed` events whose job's
   `external_reference` starts with `address-batch:` belong to catto-mail administrator batches,
   not to a ctnlist validation; `AddressValidation` should leave them alone (today they would not
   match a ctnlist job).
4. **Export for re-permission.** A list export (CSV) of the addresses to ask, without addresses
   that are already confirmed or unsubscribed, so the batch is exactly the population to ask.
5. **Tests.** Extend ctnlist's `FakeCattoMail` and webhook tests with `repermission.responded`
   (the three answers, an out-of-order older answer, an unknown address).

Nothing else changes: ctnlist keeps sending its campaigns through `/v1/send-jobs`, and catto-mail
keeps checking suppressions before every message.

## Running the two development stacks together

The stacks are separate; the route between them is the internal Podman network `cattomail-dev`
(`docs/development-environment.md`, section 3, *Client development route*; ctnlist's README,
*catto-mail development route*):

1. In ctnlist: `bin/dev cattomail-link`, and in its `dev/podman/ctnlist.env`
   `CATTOMAIL_API_CONNECT_HOST=catto-mail:443`, `CATTOMAIL_CA_FILE` (from `bin/dev cattomail-ca`)
   and `CATTOMAIL_WEBHOOK_URL=http://ctnlist-web/cattomail/webhook`.
2. Here: `APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS=webhook-receiver,ctnlist-web` in `infra/.env`, then
   `smarthostctl install` and `recreate` (the pod joins `cattomail-dev` as `catto-mail`).
3. In ctnlist: Admin → Settings → Delivery, the address `https://localhost:8443`, Connect; approve
   as a client admin of a client whose sending domain is the domain of ctnlist's From address.
4. `bin/dev test-cattomail` in ctnlist sends a proof and a validation job through this pod; the
   mail lands in this pod's Mailpit and the webhooks go to `http://ctnlist-web/cattomail/webhook`.
