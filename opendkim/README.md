# opendkim/ — OpenDKIM milter container and configuration templates

Signs outbound mail for Postfix through the Milter protocol on the private Podman network (spec
`service_topology.opendkim`). It is reachable only from Postfix.

- DKIM private keys are mounted here only (`OPENDKIM_KEY_DIR`). Go, Symfony and Python never hold
  them.
- It signs only for verified sending domains with `dkim_status = active` (KeyTable and SigningTable
  in `OPENDKIM_TABLES_DIR`).
- It is attached only to Postfix's authenticated submission service. Inbound DSN traffic is never
  signed.
- If OpenDKIM fails, Postfix tempfails and Go retries. Mail is never sent unsigned.

Empty until Phase 1 (container with a development test key). Production keys arrive in Phase 8.
Contract: `docs/architecture/postfix-integration.md` §2.
