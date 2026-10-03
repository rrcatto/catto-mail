# tests/fake-smtp/ — deterministic fake SMTP service

Development and test only. `fake_smtp.py` (stdlib asyncio) answers RCPT TO deterministically:

| Recipient | Reply |
|---|---|
| domain with a label `accept-all` | 250 for every recipient (catch-all domain) |
| domain with a label `block-all` | 554 5.7.1 probe blocked by policy |
| local part `reject-550…` | 550 5.1.1 |
| local part `tempfail-450…` / `tempfail-451…` | 450 4.2.1 / 451 4.3.0 |
| local part `unavailable-421…` | 421 4.3.2, connection closed |
| local part `throttle-421…` | 421 4.7.0 rate limited, connection closed |
| local part `block-554…` | 554 5.7.1 |
| local part `timeout…` | no reply |
| local part `smarthost-probe-…` (the validator's random accept-all probe) | 550 5.1.1 unless the domain is accept-all |
| anything else | 250 2.1.5 |

* EHLO advertises 8BITMIME and SMTPUTF8.
* DATA is accepted and discarded; nothing is stored or relayed.
* Every received command verb is logged to stdout as JSON (`{"service":"fake-smtp","cmd":"RCPT"}`)
  and kept in `COMMANDS` for in-process tests. The Phase 3 tests use this to prove that the
  validator never sends DATA.
* Connection refusal is simulated with a closed port.
* The validator's test image includes this file as `tests/fakes/fake_smtp.py`.
