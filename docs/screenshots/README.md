# Screenshots

Every page of the dashboard as of version 0.2.2, taken on the development pod (`smarthostctl`) at 1,440 pixels wide unless
noted, signed in as the administrator. The figures come from the end-to-end test suites, so the clients, addresses and
domains are test data. Times are shown in the installation time zone (`APP_TIMEZONE`, here SAST). The development pod is
always in HELD mode: live delivery is off and mail goes to Mailpit.

## Operator console

### Overview

`/dashboard/operator` · Period pills, the delivery banner, send jobs by hard-bounce rate, submission rate, KPIs, mail flow, needs attention, workers and queues, per-client rates, validation, health, suppressions and activity.

![Overview](operator-overview.png)

### Mail flow › Delivery

`/dashboard/operator/system` · The delivery mode with its controls, the delivery daemon, what each mode means, the submission rate, the Postfix queue, host requests and components.

![Mail flow › Delivery](operator-mail-flow-delivery.png)

### Mail flow › Suppressions

`/dashboard/operator/suppressions`

![Mail flow › Suppressions](operator-mail-flow-suppressions.png)

### Mail flow › Unmatched DSNs

`/dashboard/operator/unmatched-dsns`

![Mail flow › Unmatched DSNs](operator-mail-flow-unmatched-dsns.png)

### An unmatched DSN

`/dashboard/operator/unmatched-dsns/{id}`

![An unmatched DSN](operator-mail-flow-unmatched-dsn.png)

### Mail flow › Webhooks

`/dashboard/operator/webhooks`

![Mail flow › Webhooks](operator-mail-flow-webhooks.png)

### Clients › Clients

`/dashboard/operator/clients`

![Clients › Clients](operator-clients.png)

### A client profile

`/dashboard/operator/clients/{id}` · Lifecycle controls, account, service policy, limits and quotas, usage, reputation, API keys, domains, members, private notes and audit history.

![A client profile](operator-client-profile.png)

### Clients › Abuse & reputation

`/dashboard/operator/alerts`

![Clients › Abuse & reputation](operator-clients-abuse-reputation.png)

### Clients › Usage & billing

`/dashboard/operator/usage`

![Clients › Usage & billing](operator-clients-usage-billing.png)

### Clients › Address batches

`/dashboard/operator/batches`

![Clients › Address batches](operator-clients-address-batches.png)

### System › System setup

`/dashboard/operator/setup`

![System › System setup](operator-system-setup.png)

### System setup: the DNS step

`/dashboard/operator/setup/dns` · The steps on the left; the step’s records, checks and state on the right.

![System setup: the DNS step](operator-system-setup-dns.png)

### System › Diagnostics

`/dashboard/operator/system/diagnostics`

![System › Diagnostics](operator-system-diagnostics.png)

### Host requests

`/dashboard/operator/system/requests`

![Host requests](operator-system-host-requests.png)

### System › Audit log

`/dashboard/operator/audit`

![System › Audit log](operator-system-audit-log.png)

### Access › Users

`/dashboard/operator/users`

![Access › Users](operator-access-users.png)

### A user

`/dashboard/operator/users/{id}`

![A user](operator-access-user.png)

### Access › Roles & permissions

`/dashboard/operator/roles`

![Access › Roles & permissions](operator-access-roles.png)

### A role

`/dashboard/operator/roles/{id}`

![A role](operator-access-role.png)

### Help

`/dashboard/operator/help`

![Help](operator-help.png)

### A help topic (DNS)

`/dashboard/operator/help/dns`

![A help topic (DNS)](operator-help-dns.png)

### Search

`/dashboard/search?q=smarthost` · The round search button at the top right; results are grouped by kind.

![Search](operator-search.png)

## Client workspace

### Overview

`/dashboard/c/{client}` · Period pills, messages per day, quota rings, KPIs, validation, sending domains, engagement and recent jobs (opened by an operator, hence the strip at the top).

![Overview](client-overview.png)

### Sending › Send jobs

`/dashboard/c/{client}/send-jobs`

![Sending › Send jobs](client-sending-send-jobs.png)

### A send job

`/dashboard/c/{client}/send-jobs/{id}` · The 10,000-recipient end-to-end test job.

![A send job](client-sending-send-job.png)

### A message timeline

`/dashboard/c/{client}/messages/{id}`

![A message timeline](client-sending-message.png)

### Sending › Suppressions

`/dashboard/c/{client}/suppressions`

![Sending › Suppressions](client-sending-suppressions.png)

### Validation › Validation jobs

`/dashboard/c/{client}/validation-jobs`

![Validation › Validation jobs](client-validation-jobs.png)

### A validation job

`/dashboard/c/{client}/validation-jobs/{id}`

![A validation job](client-validation-job.png)

### Settings › Sending domains

`/dashboard/c/{client}/sending-domains`

![Settings › Sending domains](client-settings-sending-domains.png)

### Settings › API keys

`/dashboard/c/{client}/api-keys`

![Settings › API keys](client-settings-api-keys.png)

### Settings › Webhooks

`/dashboard/c/{client}/webhooks`

![Settings › Webhooks](client-settings-webhooks.png)

### Usage & limits

`/dashboard/c/{client}/usage`

![Usage & limits](client-usage.png)

## Public pages

### Sign in

`/dashboard/login` · Passwordless: the link arrives by email.

![Sign in](public-sign-in.png)

### API documentation

`/docs/api`

![API documentation](public-api-docs.png)

### Error page (404)

`any unknown path`

![Error page (404)](public-error-404.png)

## Phone width (390 pixels)

### Operator overview

`/dashboard/operator` · The area buttons become a row; wide tables scroll inside their cards.

![Operator overview](phone-operator-overview.png)

### Mail flow › Delivery

`/dashboard/operator/system`

![Mail flow › Delivery](phone-operator-mail-flow-delivery.png)

### Clients › Clients

`/dashboard/operator/clients`

![Clients › Clients](phone-operator-clients.png)

### Client overview

`/dashboard/c/{client}`

![Client overview](phone-client-overview.png)

### Sending › Send jobs

`/dashboard/c/{client}/send-jobs`

![Sending › Send jobs](phone-client-send-jobs.png)
