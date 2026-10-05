# DSN and ARF fixtures

Synthetic, realistic inbound bounce/complaint messages for the parser unit
tests (`internal/dsn`) and the PostgreSQL integration tests
(`internal/integration`). They contain only reserved example domains.
Placeholders are substituted by the tests:

| Placeholder | Meaning |
|---|---|
| `{{VERP}}` | envelope recipient, e.g. `bounce+<token>@<bounce domain>` (or `postmaster@...`) |
| `{{ENVID}}` | Smarthost message id as ENVID (Original-Envelope-Id) |
| `{{MSGID}}` | Smarthost message id in the returned Message-ID / X-Smarthost-Message-ID |
| `{{BOUNCE}}` | bounce domain |
| `{{QID}}` | Postfix queue id |
| `{{RCPT}}` | original recipient address |
| `{{OTHER}}` | another recipient address |
| `{{FROM}}` | original sender (job sender identity) |
| `{{VERP_SENDER}}` | the original message's envelope sender (its VERP return path) |
| `{{B64_STATUS}}` | a base64-encoded delivery-status body (encoded-parts.eml) |
