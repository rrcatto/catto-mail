# postfix/ — Postfix container and configuration templates

Handles SMTP transport, queueing, retry and TLS.

Production ports:
- **25:** inbound for the bounce domain only: VERP return paths (`bounce+<token>@<bounce domain>`),
  `postmaster@` and the feedback-loop address `<SMARTHOST_VERP_LOCAL_PART>@<bounce domain>` (the
  address to register with mailbox providers' feedback loops). Mail is delivered by `virtual(8)` to
  the shared DSN Maildir spool, where the Go delivery daemon claims and processes it (DSNs and ARF
  complaints, Phase 5). No milter applies, and port 25 never relays.
  - **Production:** port 25 is reachable only from nginx, on the ingress network
    (`postfix-ingress:25`, `smtpd_upstream_proxy_protocol = haproxy`). nginx inherits the public
    socket from systemd and sends each client's real address in the PROXY header, so Postfix logs
    and checks the real peer. A container-local `127.0.0.1:25` serves the health check.
  - **Development:** the plain port-25 listener is unchanged.
- **587:** authenticated submission, with the OpenDKIM milter (`milter_default_action = tempfail`).
  Two SASL accounts: the Go delivery daemon (`SMARTHOST_SUBMISSION_*`) and the Symfony application,
  which sends only the dashboard sign-in emails (`APP_MAIL_SUBMISSION_*`). Each account may use
  only its own envelope senders (`smtpd_sender_login_maps`, 553 otherwise): the delivery daemon
  its VERP return paths, the application `APP_MAIL_FROM`.

Postfix writes its log (`maillog_file`) and periodic atomic `postqueue -j` snapshots into the
shared observability volume, which Go reads read-only.

Delivery modes (`entrypoint.sh`; `docs/architecture/postfix-integration.md` §11):

| Mode | When | Behaviour |
|---|---|---|
| capture | development/test | relays only to Mailpit (`POSTFIX_RELAYHOST`) |
| held | production, `SMARTHOST_LIVE_DELIVERY_ENABLED=false` | no relayhost; outbound recipients refused with 450 except `APP_MAIL_FROM` |
| live | production, `true` | direct MX delivery |

The emergency pause (`smarthost-postfix-control pause|resume|status`, driven by `smarthostctl prod
pause`) writes a durable flag in the observability volume and sets `defer_transports = smtp`.

Files:
- `Containerfile`;
- `entrypoint.sh`: builds `main.cf`/`master.cf` from the environment contract, with the
  capture/held/live safety switch, the pause flag, the shared-volume layout, the bounce-domain
  recipient table and the sender-login table;
- `healthcheck.sh`;
- `control.sh` (`smarthost-postfix-control`): the emergency pause;
- `logrotate.sh` and `queue-snapshot.sh`: run by the systemd user timers.
Contract: `docs/architecture/postfix-integration.md`.
