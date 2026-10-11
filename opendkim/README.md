# opendkim/ — OpenDKIM milter container and configuration templates

Signs outbound mail for Postfix through the Milter protocol on the private Podman network (spec
`service_topology.opendkim`). It runs in the `smarthost` pod, is never host-published, and Postfix
is its only client.

- DKIM private keys are mounted here only (`OPENDKIM_KEY_DIR`). Go, Symfony and Python never hold
  them.
- It signs only for verified sending domains with `dkim_status = active` (KeyTable and SigningTable
  in `OPENDKIM_TABLES_DIR`).
- It is attached only to Postfix's authenticated submission service. Inbound DSN traffic is never
  signed.
- If OpenDKIM fails, Postfix tempfails and Go retries. Mail is never sent unsigned.

Files:
- `Containerfile`;
- `entrypoint.sh`: configuration from the environment contract;
- `dev-key.sh`: generates a disposable development key; refused outside `development`/`test`; run
  by `smarthostctl dkim-dev-key`;
- `dkim-key.sh` (`smarthost-dkim-key`): production keys (Phase 8), run by `smarthostctl prod dkim`
  (by the operator, or by the host agent for the client dashboard's sending domains: it passes on
  only the public half, from `pubkey`).
  - Commands: `generate`, `activate`, `retire`, `dns`, `pubkey`, `list`.
  - Several domains and selectors at once.
  - A new key signs only after `activate`. Generation never overwrites a key, and the active
    selector cannot be retired.
  - OpenDKIM applies a changed KeyTable or SigningTable after `SIGUSR1`, at the start of the
    next milter session, without a restart. `smarthostctl prod dkim activate` sends the signal
    and proves the new key signs with a milter probe; it restarts the container only when that
    fails.
  - Planned rotation: generate, publish, activate, wait, retire (runbook §6).
Contract: `docs/architecture/postfix-integration.md` §2.
