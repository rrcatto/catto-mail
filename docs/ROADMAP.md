# Roadmap

Work the owner has asked for later, recorded so that it is not forgotten. An item says what to do first; nothing in it is decided beyond what it states, and the specification stays the authority once work starts.

## Dashboard: an htmx inventory

Asked for by the owner on 2026-10-27, when ctnlist adopted htmx: search fields that search as you type, and parts of a page that update without reloading it. The dashboard uses no htmx today. Its pages are server-rendered Twig with Stimulus controllers (AssetMapper), and every search, filter, page turn and action reloads the whole page.

First step: an inventory of the dashboard (operator console, client workspace, the connect and sign-up pages) that lists where htmx would remove a reload or a submit button, for the owner to choose from before anything is built. Places to look at:

- search fields: clients, users, the search page, suppressions, the audit log;
- filters and pagination of the long lists (send jobs, validation jobs, messages, webhook deliveries, alerts);
- actions that change one row or one card: client status changes, memberships, API keys, webhook endpoints, sending domain actions;
- state that changes while a page is open: a sending domain's DNS checks and DKIM activation, validation and send jobs in progress, diagnostics, the setup wizard.

Rules to keep: every flow still works without JavaScript, a GET request changes nothing, and the Content-Security-Policy (`script-src` by nonce, `style-src 'self'`) stays as it is, so htmx runs without `eval` and without inline styles.

## Sending domains: DNS records made automatically

Mentioned by the owner on 2026-10-27 as possibly useful one day, and decided "not now": revisit after the public launch. Each domain's TXT and DKIM records are published by hand today, guided one step at a time on the domain's page. Making them automatically needs each DNS host's API (Virtualmin and Cloudflare first, for example) and a login to it that the customer hands over, so the first step is a note of which hosts the customers use and how such a login would be stored.
