# Integrating ctnlist with catto-mail

Specification 2.11. This describes the integration of the ctnlist mailing-list application with
catto-mail: what each side owns, the API calls and webhook events, what ctnlist already does,
and the **ctnlist-side work that is still outstanding** for the re-permission workflow. The
ctnlist repository is maintained separately; nothing in it was changed as part of this work.

## Responsibilities

```text
ctnlist     = subscribers, lists, memberships (confirmed / unsubscribed), campaigns, content,
              ordinary unsubscribes, consent records — the mailing-list and business state

catto-mail  = validation, sending, suppression, delivery events, bounces, complaints,
              tracking, mail infrastructure (DNS identity, DKIM, Postfix, reputation)
```

They never share a database. ctnlist uses catto-mail only through the authenticated API
(`/v1`, an API key of the ctnlist client) and receives signed webhooks.

## What ctnlist already does (read from its repository, release 6.0.5)

| Concern | catto-mail API / event | ctnlist |
|---|---|---|
| Validate a list's addresses | `POST /v1/validation-jobs`, `GET /v1/validation-jobs/{id}`, `GET /v1/validation-jobs/{id}/addresses` | `AddressValidation`, `CattoMailClient` |
| Send a campaign | `POST /v1/send-jobs`, `POST /v1/send-jobs/{id}/recipients` (≤ 500 per batch), `POST /v1/send-jobs/{id}/submit` | `CattoMailSender`, `OutgoingMessageFactory` (List-Id `Name <shortcode.domain>`, per-recipient unsubscribe URL) |
| Follow results | `GET /v1/send-jobs/{id}`, `GET /v1/send-jobs/{id}/messages`, `GET /v1/messages/{id}/events` | `SendJobSync` (polling fallback) |
| Recipient global opt-out | `POST /v1/global-suppressions`, `GET /v1/global-suppressions/{id}`, `POST /v1/global-suppressions/{id}/lift` | `GlobalOptOut` |
| Webhooks | `validation.completed/failed`, `send.completed/failed`, `message.hard_bounced`, `message.complained`, `webhook.test`; signature `Smarthost-Signature`, de-duplication by `Smarthost-Event-Id` | `WebhookProcessor`, `WebhookSignature`; unknown event types are recorded and ignored |

Ordinary list unsubscribes stay in ctnlist (its unsubscribe links), as catto-mail requires: they
are never reported to catto-mail as global opt-outs.

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
  "id": "0199…", "type": "repermission.responded", "created_at": "2026-10-07T11:19:00Z",
  "data": {
    "batch": {"id": "0199…", "name": "2019 newsletter list", "list_id": "news.example.org"},
    "entry_id": "0199…",
    "address": "Reader@example.org",
    "response": "confirmed",
    "responded_at": "2026-10-07T11:18:58Z"
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
