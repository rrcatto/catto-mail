# Webhook receiver test fixture

A deterministic stand-in for a **client application's** webhook endpoint, used by
`infra/bin/smarthostctl test phase7-e2e`. It is not a Smarthost service: it runs in its own
container (`smarthost-test-webhook-receiver`, network alias `webhook-receiver` on
`smarthost-internal`), contains no Smarthost code, and never touches the Smarthost database.

`receiver.py` (Python standard library only) implements the receiver rules a real client should
follow:

1. read the raw body;
2. check `Smarthost-Signature` (`t=<unix>,v1=<hex>[,v1=<hex>]`): HMAC-SHA256 over `<t>.<body>`
   with any configured secret (current, plus previous during a rotation), compared in constant time,
   with the timestamp no more than 300 s from the local clock;
3. parse the JSON only after that;
4. de-duplicate on the event id: a repeated event is acknowledged but has no second effect;
5. keep the newest state per subject by `created_at` (out-of-order arrival never regresses it),
   and ignore unknown event types;
6. persist, then answer 2xx.

Test control endpoints (not a client contract):

| Endpoint | Purpose |
|---|---|
| `POST /_control/secrets` `{"endpoint", "secrets": [...]}` | Secrets for `/hooks/<endpoint>` |
| `POST /_control/script` `{"endpoint", "responses": [...]}` | Next responses: `200`, `400`, `429[:retry-after]`, `500`, `timeout[:s]`, `drop`, `delay[:s]` |
| `POST /_control/reset` | Forget everything |
| `GET /_control/state` | Receipts, accepted events, effects, rejected requests |

State is kept in `RECEIVER_STATE_FILE` (default `/data/state.json`), so it survives a restart.
`test_receiver.py` unit-tests the verification and the idempotent processing without a network.
