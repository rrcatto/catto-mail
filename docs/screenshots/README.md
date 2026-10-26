# Screenshots

Every page of the dashboard after the layout of 2026-10-25 (a three-tier menu bar, a breadcrumb, at most four key figures
and one main list per page, explanations behind ⓘ), retaken on 2026-10-26 from the code after version 0.2.8, so every
footer shows 0.2.8. Three pages that could not be reproduced on the development pod keep their screenshots of 2026-10-12
(0.2.6): the two connect pages and sign-up; their templates did not change, only the type scale. They were taken on the
development pod (`smarthostctl`) at 1,440 pixels wide unless noted, each page at its full length, signed in as the
administrator (the public pages signed out). The installation is named "Catto Mail" here, the default of `APP_SITE_NAME`.
The figures come from the end-to-end test suites, so the clients, addresses and domains are test data; the overviews show
30 days because the test data is older than the default 7. Every time is in the installation time zone
(`SMARTHOST_TIMEZONE`, here SAST). The development pod is always in HELD mode: live delivery is off and mail goes to Mailpit.

## Operator console

### The menu bar

`/dashboard/operator/audit` with the System menu open · Pointing at an area opens its pages; a page with an arrow opens
its views, here the audit log's (sign-in emails, sign-ins, sign-up emails …). A number counts what needs a look. Below
1,100 pixels the bar folds behind a *Menu* button.

![The menu bar](operator-menu-bar.png)

### Overview at 2,560 pixels

`/dashboard/operator?period=30d` · The same page on a wide screen: the content keeps a readable width.

![Overview at 2,560 pixels](wide-operator-overview.png)

### Overview

`/dashboard/operator?period=30d` · What needs attention, four key figures against the previous period, messages per day, the five clients to watch and system health; the period at the top right.

![Overview](operator-overview.png)

### Mail flow › Delivery

`/dashboard/operator/system` · The delivery mode with its controls, workers and queues (the Postfix queue among them), the submission rate and the latest host requests.

![Mail flow › Delivery](operator-mail-flow-delivery.png)

### Mail flow › Suppressions

`/dashboard/operator/suppressions`

![Mail flow › Suppressions](operator-mail-flow-suppressions.png)

### Mail flow › Unmatched bounces

`/dashboard/operator/unmatched-dsns`

![Mail flow › Unmatched bounces](operator-mail-flow-unmatched-dsns.png)

### An unmatched bounce

`/dashboard/operator/unmatched-dsns/{id}`

![An unmatched bounce](operator-mail-flow-unmatched-dsn.png)

### Mail flow › Webhooks

`/dashboard/operator/webhooks`

![Mail flow › Webhooks](operator-mail-flow-webhooks.png)

### Clients › Clients

`/dashboard/operator/clients` · Views as chips (all, public sign-ups, waiting for approval, throttled, suspended), the other filters under *More filters*, and every client with its status, alerts and domains.

![Clients › Clients](operator-clients.png)

### A client profile

`/dashboard/operator/clients/{id}` · The Summary tab: four key figures, the status with its actions, the account and private notes. The other tabs hold limits and usage, reputation, domains and webhooks, keys and members, the policy and opt-outs, and the history.

![A client profile](operator-client-profile.png)

### A client profile: Limits & usage

`/dashboard/operator/clients/{id}?tab=limits`

![A client profile: Limits & usage](operator-client-limits.png)

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

`/dashboard/operator/setup` · The 19 steps in groups, each with its checks' result in one line; a step opens to its checks and *Mark done*.

![System › System setup](operator-system-setup.png)

### System setup: the DNS step

`/dashboard/operator/setup/dns` · The step's records and checks, the previous and next step, and its state.

![System setup: the DNS step](operator-system-setup-dns.png)

### System › Diagnostics

`/dashboard/operator/system/diagnostics`

![System › Diagnostics](operator-system-diagnostics.png)

### System › Settings

`/dashboard/operator/system/settings` · One group of operational settings at a time, chosen in the menu or from the chips.

![System › Settings](operator-system-settings.png)

### Host requests

`/dashboard/operator/system/requests`

![Host requests](operator-system-host-requests.png)

### System › Audit log

`/dashboard/operator/audit` · Views as chips (sign-in emails, sign-ins, …) and the full filter form under *More filters*; each entry's details behind ⓘ.

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

`/dashboard/search?q=smarthost` · The search button at the top right; results are grouped by kind.

![Search](operator-search.png)

## Client workspace

### Overview

`/dashboard/c/{client}?period=30d` · *Get started* (until the client has a sending domain that can send, an API key and a webhook), the state and the limits in one line, four key figures, the latest send jobs and one status per sending domain.

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

`/dashboard/c/{client}/sending-domains` · Every domain with its state in one table, then each domain's records to publish and *Check DNS now*; an active domain keeps its records folded away. Client admins add a domain within the client's limit.

![Settings › Sending domains](client-settings-sending-domains.png)

### Settings › API keys

`/dashboard/c/{client}/api-keys`

![Settings › API keys](client-settings-api-keys.png)

### Settings › Webhooks

`/dashboard/c/{client}/webhooks`

![Settings › Webhooks](client-settings-webhooks.png)

### Settings › Connected applications

`/dashboard/c/{client}/connections` · The applications a client admin connected from the connect page: where each one's
webhooks go, its API key's prefix and last use, who approved it, and *Disconnect*, which revokes the key and removes the
endpoint. An ended connection stays in the list.

![Settings › Connected applications](client-settings-connected-applications.png)

### Connect an application: the approval

`/dashboard/connect?…` signed in · What the application asks for, the workspace to connect (here it replaces an earlier
connection to the same webhook address), *Connect* and *Cancel*, and what connecting does.

![Connect an application: the approval](connect-approve.png)

### Usage & limits

`/dashboard/c/{client}/usage`

![Usage & limits](client-usage.png)

## Public pages

### Front page

`/` · What someone who types the installation's address sees: sign-in, the API documentation and a word for recipients of
its mail. A full-width banner over four quarter cards.

![Front page](public-front-page.png)

### Sign up

`/dashboard/sign-up` · Only when the operator turns public sign-up on (`APP_PUBLIC_ONBOARDING_ENABLED=true`; otherwise 404). An organisation and an email address; the confirmation link arrives by email, and the account opens in the starter tier when it is used.

![Sign up](public-sign-up.png)

### Sign in

`/dashboard/login` · Passwordless: the link arrives by email.

![Sign in](public-sign-in.png)

### Connect an application: a visitor

`/dashboard/connect?…` · Where an application sends a person to be connected: what is asked, and sign-in. *Create an
account* is offered beside it only while public sign-up is on. Nothing is connected before a client admin approves.

![Connect an application: a visitor](public-connect.png)

### Acceptable use policy

`/acceptable-use-policy` · Built from `docs/AUP.md`; every page's footer links to it. The document names no product: the page fills in the installation's name, its domain (here `localhost`) and who runs it from the configuration. Abuse reports go to `abuse@` that domain, the only address on the page: the footer here leaves the administrator's address out.

![Acceptable use policy](public-acceptable-use-policy.png)

### API documentation

`/docs/api`

![API documentation](public-api-docs.png)

### Error page (404)

`any unknown path` · Taken from Symfony's error preview (`/_error/404`), because the development pod shows its debug page
instead.

![Error page (404)](public-error-404.png)

## Phone width (390 pixels)

### Front page

`/` · The four cards stack in one column.

![Front page](phone-front-page.png)

### Operator overview

`/dashboard/operator?period=30d` · The menu bar folds behind *Menu*; the cards fall to one column and wide tables scroll
inside their cards.

![Operator overview](phone-operator-overview.png)

### Mail flow › Delivery

`/dashboard/operator/system`

![Mail flow › Delivery](phone-operator-mail-flow-delivery.png)

### Clients › Clients

`/dashboard/operator/clients`

![Clients › Clients](phone-operator-clients.png)

### Client overview

`/dashboard/c/{client}?period=30d`

![Client overview](phone-client-overview.png)

### Sending › Send jobs

`/dashboard/c/{client}/send-jobs`

![Sending › Send jobs](phone-client-send-jobs.png)
