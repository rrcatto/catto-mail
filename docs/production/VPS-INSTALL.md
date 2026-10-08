# Installing catto-mail on an Ubuntu VPS

This is the primary installation guide (specification 2.11). It takes you from renting a server
to a working, tested installation. It assumes you can SSH to a server, type commands and edit a
text file — and nothing else: every catto-mail concept is explained on the way, and the web
application's **System setup** wizard and **Help** pages continue where this guide stops.

Names used as examples throughout (replace them with yours):

| What | Example | What it is |
|---|---|---|
| Web/API host name | `mail.example.com` | The address of the dashboard, the client API and the tracking links. |
| Mail host name | `mta.example.com` | The name the mail server uses to greet other mail servers; the reverse DNS (PTR) of the IP. |
| Bounce domain | `bounce.example.com` | Every message's hidden return address (`bounce+…@bounce.example.com`); bounces and complaints come back here. |
| Sending domain | `news.example.org` | The domain in the visible From address of the mail you send (it can belong to a client). |
| From address | `newsletter@news.example.org` | A sender at the sending domain. |
| Administrator | `admin@example.com` | Your mailbox; it always has the ADMIN role. |
| Server IP | `203.0.113.10` | The VPS's static public IPv4 address. |

Contents: [1 What you need](#1-what-you-need-from-the-vps-provider) ·
[2 Install](#2-install) · [3 First sign-in](#3-first-sign-in) · [4 DNS](#4-dns) ·
[5 Certificates](#5-certificates-lets-encrypt) · [6 DKIM](#6-dkim) ·
[7 The setup wizard](#7-the-setup-wizard) · [8 Firewall](#8-firewall) ·
[9 Backups](#9-backups) · [10 Reboot test](#10-reboot-test) · [11 Going live](#11-going-live) ·
[12 Versions, upgrades, rollback](#12-versions-upgrades-and-rollback) ·
[13 What catto-mail cannot do for you](#13-what-catto-mail-cannot-do-for-you) ·
[14 Acceptance checklist](#14-real-vps-acceptance-checklist) ·
[15 Troubleshooting](#15-troubleshooting-the-installation) ·
[16 What was tested where](#16-what-was-tested-locally-and-what-only-a-real-vps-can-prove)

---

## 1. What you need from the VPS provider

| Requirement | Why | Ask / check |
|---|---|---|
| **Ubuntu Server 26.04 LTS** | The installer supports and is tested against it. | Choose it when ordering. |
| **A static public IPv4 address** | Mail reputation is tied to the sending IP. catto-mail sends over IPv4 only. | Usually included. |
| **Outbound TCP 25 allowed** | Postfix delivers to recipients' mail servers on port 25. Many providers block it on new accounts. | Ask support to unblock it. The setup wizard tests it. |
| **Inbound TCP 25 allowed** | Bounces and complaints arrive on port 25. | Usually open; check the provider's firewall panel. |
| **Inbound TCP 443 allowed** | The dashboard, the API, tracking and re-permission links. | Usually open. |
| **Control of the PTR (reverse DNS) of the IP** | Receivers reject mail from IPs without a matching PTR. | The provider's panel ("reverse DNS", "rDNS") or support. |
| **SSH access as root** | To run the installer. | Standard. |
| **Enough resources** | See below. | |

**Sizing.** Measured on the development machine: all eight services together use about 275 MB of
memory when idle; the container images take about 1.6 GB, and building them needs another 3–5 GB
temporarily. The load tests (10,000-address validation jobs, 10,000-message send jobs, a million
usage records) ran comfortably within 8 GB. Real production sizing under sustained load has
**not** been measured, so these are conservative recommendations:

| | Minimum (the installer refuses less) | Recommended |
|---|---|---|
| CPU | 2 vCPU | 2–4 vCPU |
| Memory | 2 GB (4 GB strongly advised) | 8 GB |
| Disk | 40 GB SSD (15 GB free at install) | 80 GB SSD (event history, queue, local backups grow) |

You also need a **domain** whose DNS you control (for the web, mail and bounce names) and, for
automatic certificates, a DNS provider with an API (most have one; see §5).

## 2. Install

catto-mail is installed from an exact **release**: a Git tag such as `v0.2.0`, which always
names the same code (unlike the `main` branch, which moves). List the releases:

```sh
git ls-remote --tags https://github.com/rrcatto/catto-mail.git | sed 's#.*refs/tags/##'
```

Each release is also listed, with its notes and source downloads, on the
[releases page](https://github.com/rrcatto/catto-mail/releases). `v0.1.9` is the first release
with this installer.

As **root** on the new server (replace `vX.Y.Z` with the newest release, e.g. `v0.2.0`):

```sh
apt-get update && apt-get install -y git
git clone --branch vX.Y.Z --depth 1 https://github.com/rrcatto/catto-mail.git /root/catto-mail-installer
/root/catto-mail-installer/install-catto-mail
```

The installer asks for the web host name, mail host name, bounce domain, administrator address
and the sign-in sender (defaults are offered). To run it unattended, give everything as options:

```sh
/root/catto-mail-installer/install-catto-mail --non-interactive \
  --web-host mail.example.com --mta-host mta.example.com --bounce-domain bounce.example.com \
  --admin-email admin@example.com --mail-from no-reply@example.com
```

Optional: `--acme-email you@example.com --acme-provider cloudflare --acme-credentials /root/acme-dns.env`
obtains a Let's Encrypt certificate during installation (§5), and `--apply-firewall` applies the
firewall rules (§8). `--help` lists every option.

### What the installer does

Every step prints `PASS`, `WARN` or `FAIL`. On a FAIL it says what failed, why, how to fix it,
and whether re-running is safe; the full log is `/var/log/catto-mail-install.log`. Re-running is
always safe after fixing the cause: finished steps are checked and skipped, and existing
configuration and data are kept.

```text
[01/21] Checking that this is root on Ubuntu 26.04 ......... PASS
[02/21] Checking memory, disk and the public address ....... PASS
[03/21] Updating the package lists ......................... PASS
[04/21] Installing Podman, Git, Python, OpenSSL, nftables, lego PASS
[05/21] Creating the service user cattomail ................ PASS
[06/21] Enabling lingering (start at boot without a login) . PASS
[07/21] Allowing the service user to listen on ports 25 and 443 PASS
[08/21] Starting the user's Podman socket .................. PASS
[09/21] Fetching the release ............................... PASS
[10/21] Writing the configuration (infra/.env) ............. PASS
[11/21] Checking the configuration ......................... PASS
[12/21] Building the container images (several minutes) .... PASS
[13/21] Installing the certificates ........................ WARN  a temporary self-signed certificate; …
[14/21] Installing the systemd units and creating the containers PASS
[15/21] Starting catto-mail in HELD mode ................... PASS
[16/21] Checking the health of every component ............. PASS
[17/21] Running the host checks ............................ PASS
[18/21] Taking the first backup ............................ PASS  (/home/cattomail/catto-mail/infra/.generated/backups/…)
[19/21] Preparing the firewall rules ....................... WARN  generated …, not applied …
[20/21] Checking automatic start after a reboot ............ PASS
[21/21] Creating the first sign-in link .................... PASS
```

In detail:

1. Checks Ubuntu 26.04, root, the CPU architecture and that systemd runs.
2. Checks memory and disk, detects the public IPv4 and the SSH port.
3. – 4. Installs from Ubuntu: `podman` and its rootless helpers (`uidmap`, `passt`,
   `slirp4netns`, `netavark`, `aardvark-dns`, `crun`, `fuse-overlayfs`, `catatonit`,
   `dbus-user-session`, `systemd-container`), `git`, `python3`, `openssl`, `nftables`, `curl`,
   `rsync`, `openssh-client` and the ACME client `lego`. Nothing else: PHP, PostgreSQL, Postfix,
   Go and Python libraries are inside the container images.
5. Creates the unprivileged user `cattomail` (with a subordinate UID/GID range for rootless
   Podman). Everything catto-mail runs belongs to this user.
6. Enables **lingering**: the user's own systemd runs without anyone logged in and starts at
   boot.
7. Writes `/etc/sysctl.d/60-catto-mail-ports.conf` with `net.ipv4.ip_unprivileged_port_start=25`
   and applies it. Linux reserves ports below 1024 for root; this lets the unprivileged service
   hold 25 and 443. Ports 1–24, including SSH (22), stay privileged. It is system-wide, which is
   acceptable on a server dedicated to catto-mail.
8. Starts the user's Podman socket and confirms Podman runs rootless.
9. Clones the release into `/home/cattomail/catto-mail` (refuses to replace a different release:
   that is an upgrade, §12).
10. Creates `infra/.env` from the production template with your answers and freshly generated
    secrets (mode 0600; the secrets exist only on this server and in its backups).
11. Validates it against the production safety rules (`smarthostctl prod check`).
12. Builds the container images, tagged with the release version.
13. Installs a certificate: Let's Encrypt if you gave the ACME options and DNS already points
    here, otherwise a temporary self-signed one so everything can start (§5 replaces it).
14. Installs the systemd user units (`smarthost.service` in the boot target, the ingress socket,
    the host agent, the timers) and creates the networks, volumes and containers.
15. Starts everything: PostgreSQL, the database roles, migrations and grants, then each service.
    Delivery starts **HELD**: nothing is delivered to the Internet.
16. Waits until every container reports healthy.
17. Runs the host, runtime and exposure checks.
18. Takes the first backup.
19. Writes the firewall rules to `/home/cattomail/catto-mail-firewall.nft` (applies them only with
    `--apply-firewall`, §8).
20. Confirms catto-mail will start by itself after a reboot.
21. Prints a single-use sign-in link.

It ends like this:

```text
Catto-mail installation completed.

Web application:    https://mail.example.com/
Administrator:      admin@example.com
Release:            vX.Y.Z
Live bulk delivery: HELD (nothing is delivered to the Internet until you enable it)

First sign-in (valid 15 minutes, one use; make a new one with
  sudo -u cattomail /home/cattomail/catto-mail/infra/bin/smarthostctl prod admin-link):
  https://mail.example.com/dashboard/login/verify?token=…

Next step:
  Open https://mail.example.com/ and complete the Administration Setup Wizard
```

**BIND is not required on the catto-mail VPS.** catto-mail only *reads* DNS; your domains'
records stay with your DNS provider. Do not install a DNS server, and do not install Ubuntu's
Postfix (it would take port 25).

## 3. First sign-in

catto-mail has no passwords: you sign in with single-use links. Normally the link is e-mailed to
you, but on a new server mail does not work yet (DNS, PTR and DKIM are not set up), so the
installer prints a link instead. It works once, for 15 minutes, only for an administrator, and is
recorded in the audit log. Create a new one at any time on the server:

```sh
sudo -u cattomail /home/cattomail/catto-mail/infra/bin/smarthostctl prod admin-link
```

1. Create the **A record** of the web host name first (§4), so `mail.example.com` reaches the
   server.
2. Open the link. With the temporary certificate the browser warns that the connection is not
   trusted: that is expected until §5; continue to the site.
3. You land in **System setup**. Every new installation opens it until the last step is done.

From now on all commands are run as `cattomail` in `/home/cattomail/catto-mail`:

```sh
sudo -iu cattomail
cd ~/catto-mail
infra/bin/smarthostctl prod status        # every component and the ingress socket
```

## 4. DNS

Create these records at your DNS provider (and the PTR at your VPS provider). System setup › DNS
lists them with your real names and tests each one; `infra/bin/smarthostctl prod dns-checklist`
prints them too.

| Type | Name | Value | Purpose |
|---|---|---|---|
| A | `mail.example.com` | `203.0.113.10` | The dashboard and API. |
| A | `mta.example.com` | `203.0.113.10` | The mail server's name. |
| **PTR** | `10.113.0.203.in-addr.arpa` (the IP) | `mta.example.com` | Reverse DNS. **Set at your VPS provider**, not in your zone. |
| MX | `bounce.example.com` | `10 mta.example.com.` | Bounces and complaints reach this server. |
| TXT (SPF) | `bounce.example.com` | `v=spf1 ip4:203.0.113.10 -all` | Authorises the IP for the envelope sender. |
| TXT (SPF) | `mta.example.com` | `v=spf1 ip4:203.0.113.10 -all` | Authorises the IP for the greeting name. |
| TXT (DKIM) | `s2026a._domainkey.news.example.org` | `v=DKIM1; k=rsa; p=MIIBIjANBg…` (from §6) | The signing key of the sending domain. |
| TXT (DMARC) | `_dmarc.news.example.org` | `v=DMARC1; p=none; rua=mailto:dmarc@news.example.org` | The sending domain's policy; start with `p=none`. |
| TXT | `_smarthost-verification.news.example.org` | the token shown when the domain is added | Proves the client controls the sending domain. |

What the terms mean:

- **A** — name → IPv4 address.
- **PTR** — IP address → name (reverse DNS). Receivers distrust IPs without one.
- **FCrDNS** (forward-confirmed reverse DNS) — the PTR name must resolve back to the same IP:
  PTR `203.0.113.10 → mta.example.com` and A `mta.example.com → 203.0.113.10`.
- **MX** — which server receives mail for a domain.
- **SPF** — which IPs may send with a domain in the hidden envelope sender.
- **DKIM** — a signature on each message; receivers check it with the public key in DNS.
- **DMARC** — what receivers should do when a message claiming your From domain fails SPF/DKIM
  alignment, and where to send reports.

The sign-in mail sender's domain (`APP_MAIL_FROM`) needs DKIM (and preferably DMARC) as well.
DNS changes take minutes to hours to be visible. Test: System setup › DNS › *Test DNS*, or
`infra/bin/smarthostctl prod preflight --section dns`.

## 5. Certificates (Let's Encrypt)

catto-mail needs a certificate for `mail.example.com` (HTTPS) and `mta.example.com` (SMTP
STARTTLS); one certificate covers both names. It uses **Let's Encrypt** with the **DNS-01**
challenge, so port 80 is never opened.

**How DNS-01 works:** the ACME client `lego` (installed on the server by the installer) asks Let's
Encrypt for a certificate; Let's Encrypt asks it to prove control of each name by creating a
temporary TXT record `_acme-challenge.<name>`; `lego` creates it through your DNS provider's API,
Let's Encrypt checks it, the record is removed, and the certificate is issued.

1. Create an API token at your DNS provider, limited to editing DNS of your zone.
2. Find lego's name for your provider and its variables:
   ```sh
   lego dnshelp                      # the provider list
   lego dnshelp -c cloudflare        # the variables one provider needs
   ```
3. As `cattomail`, create the credentials file (outside the repository, mode 0600):
   ```sh
   cat > ~/acme-dns.env <<'EOF'
   CF_DNS_API_TOKEN=your-token-here
   EOF
   chmod 600 ~/acme-dns.env
   ```
4. In `infra/.env` set:
   ```text
   ACME_EMAIL=admin@example.com
   ACME_DNS_PROVIDER=cloudflare
   ACME_CREDENTIALS_FILE=/home/cattomail/acme-dns.env
   ```
   (`ACME_SERVER` defaults to Let's Encrypt production; set it to
   `https://acme-staging-v02.api.letsencrypt.org/directory` to try first without rate limits.)
5. Obtain and install the certificate:
   ```sh
   infra/bin/smarthostctl prod tls acme issue
   ```
   It installs the certificate into nginx and Postfix with `prod tls set` (only those two
   containers restart).

**Renewal** is automatic: `smarthost-tls-renew.timer` runs `prod tls acme renew` daily and renews
30 days (`ACME_RENEW_DAYS`) before expiry. **Failure is detected**: the diagnostics show the last
renewal result and the timer's state, and the TLS checks warn 14 days before the served
certificate expires. `prod tls acme status` shows the certificate and the last attempt.

**No API at your DNS provider?** Set `ACME_DNS_PROVIDER=manual`. `prod tls acme issue` then prints
the TXT record, waits while you create it, and continues. Renewals then need you every 60–90
days. Or obtain a certificate any other way and install it:
`infra/bin/smarthostctl prod tls set proxy.crt proxy.key mta.crt mta.key`.

## 6. DKIM

Every sending domain needs a DKIM key; the private key never leaves the OpenDKIM container.
In System setup › DKIM (or with the commands):

```sh
infra/bin/smarthostctl prod dkim generate news.example.org s2026a   # new key, not signing yet; prints the TXT record
# publish the TXT record at the domain's DNS, wait until it is visible, then:
infra/bin/smarthostctl prod dkim activate news.example.org s2026a   # OpenDKIM starts signing
infra/bin/smarthostctl prod console smarthost:domain:dkim <client-id> news.example.org active --selector s2026a
```

The dashboard's *Activate* checks the published key first and records it for the domain.
Rotation: generate a new selector, publish, activate, keep the old record a week, then
`prod dkim retire`.

## 7. The setup wizard

System setup (`https://mail.example.com/dashboard/operator/setup`) is resumable and stays
available afterwards. Each step explains the subject, shows the live checks (PASS, WARN, FAIL,
SKIPPED with an explanation and a fix), and links to Help:

1. Welcome — what catto-mail is and is not; the components; the flows.
2. Host — Ubuntu, Podman, rootless, user, lingering, units, disk, memory, IP, automatic start.
3. Web identity — host name, base URL, administrator.
4. SMTP identity — mail host name, IPv4, bounce domain, EHLO.
5. DNS — every record with its status and *Test DNS*.
6. TLS — both certificates, issuer, names, expiry, trust, renewal; *Obtain or renew now*.
7. DKIM — keys, records, *Generate* and *Activate*, rotation.
8. Database — running, schema, migrations, grants.
9. Web application — health, API, tracking endpoints.
10. Email validator — heartbeat, a deterministic test job, whether SMTP probing is on.
11. Delivery daemon — heartbeat, mode, Postfix queue reconciliation.
12. Postfix — ports, relay protection, TLS, outbound 25, the milter.
13. OpenDKIM — keys, tables, DNS match.
14. Webhook worker — heartbeat and a test webhook.
15. Backups — last backup, contents, off-host copy, *Back up now*, *Rehearse a restore*.
16. Seed delivery test — send to your own addresses and follow every stage.
17. Bounce test — send to a non-existent address at your domain and see the bounce return.
18. Reboot test — reboot and confirm everything came back.
19. Production readiness — one summary; live delivery stays a separate decision.

The checks come from two places: the web application runs its own (database, workers,
tracking), and the **host agent** — a small service on the server, `smarthost-host-agent.service`
— runs the host-side ones (systemd, DNS, TLS, Postfix, backups) and carries out what you request
(backups, DKIM, renewals, holding the queue, live activation). The web application never runs
commands on the server itself. Results and their history: System › Diagnostics.

## 8. Firewall

Only SSH, 25 and 443 need to be reachable. The installer writes reviewed rules to
`/home/cattomail/catto-mail-firewall.nft` but does not apply them, so a mistake cannot lock you
out. Check that your SSH port is in the `tcp dport { … }` line, then as root:

```sh
nft -f /home/cattomail/catto-mail-firewall.nft
echo 'include "/home/cattomail/catto-mail-firewall.nft"' >> /etc/nftables.conf
systemctl enable nftables
```

Keep your SSH session open and test a new SSH connection before closing it. The rules also limit
the service user's outbound traffic to DNS, SMTP 25, HTTP(S) and SSH (off-host backups).
PostgreSQL, PHP and the workers are never reachable from outside: no container publishes a port.

## 9. Backups

A backup holds the database, `infra/.env` (all secrets and the encryption keys), the DKIM keys and
tables, the TLS certificates and a manifest. `smarthost-backup.timer` takes one daily at
`BACKUP_SCHEDULE` and keeps `BACKUP_KEEP`. Configure in `infra/.env`:

```text
BACKUP_OFFHOST_TARGET=backup@backup-host.example.net:/srv/catto-mail-backups/
BACKUP_OFFHOST_SSH_KEY=/home/cattomail/.ssh/backup_ed25519
BACKUP_ENCRYPTION_PASSPHRASE_FILE=/home/cattomail/backup-passphrase
```

- The target is any server you can reach with SSH (create a key with
  `ssh-keygen -t ed25519 -f ~/.ssh/backup_ed25519 -N ''` and add the public key there), or a
  locally mounted path. catto-mail assumes no storage provider.
- With a passphrase file (`openssl rand -base64 48 > ~/backup-passphrase; chmod 600 ~/backup-passphrase`),
  each backup is one AES-256 encrypted file. **Keep a copy of the passphrase off the server.**
- Without an off-host target the diagnostics show `WARN: backup is not off-host`.

```sh
infra/bin/smarthostctl prod backup --scheduled     # now
infra/bin/smarthostctl prod backup-status          # last backup, off-host copy, rehearsal
infra/bin/smarthostctl prod restore-rehearsal      # restore the newest into a temporary database, check, drop
infra/bin/smarthostctl prod restore <backup> --yes # a real restore (pause first)
```

Rehearse a restore monthly (System setup › Backups).

## 10. Reboot test

```sh
reboot          # as root
```

After two or three minutes, sign in again: System setup › Reboot test shows whether
`smarthost.service` started automatically, every container became healthy, the ingress socket
returned, and the delivery mode was preserved. Automatic start relies on: lingering for
`cattomail` → its systemd → `smarthost.service` (in its boot target) → Podman → the containers →
the ingress socket and nginx.

## 11. Going live

Live delivery is a deliberate, audited decision, never automatic. Before it:

- every readiness row is PASS (or a WARN you understand), including PTR, SPF, DKIM, DMARC,
  outbound 25 and a trusted HTTPS certificate;
- the seed test reached your inboxes with `dkim=pass`, and the bounce test came back;
- backups are off-host.

Then System › Health & delivery › *Request live delivery* (type `ENABLE LIVE DELIVERY`), or
`infra/bin/smarthostctl prod live-enable --operator admin@example.com --note "…"`. It runs the
activation preflight first and changes nothing if anything fails.

**Warm-up.** A new IP has no reputation. The production configuration starts at warm-up stage 0
(5 messages per minute installation-wide). Raise it stage by stage (the runbook's *Warm-up*
table) only on clean evidence: hard bounces under 2 %, complaints under 0.1 %, no deferral spikes.
Start with your seed addresses, then a controlled sample — never a whole list.

**Emergency:** the red *STOP SENDING EMAIL NOW* button on every operator page, or
`prod pause --operator … --note …`.

## 12. Versions, upgrades and rollback

- **A Git tag** (`v0.2.0`) is a permanent name for one exact version of the code. Production
  always runs a tag, never the moving `main` branch.
- **Available releases:** `git ls-remote --tags https://github.com/rrcatto/catto-mail.git`, or in
  the installation `git fetch --tags && git tag --sort=-v:refname`.
- **Installed release:** `git -C ~/catto-mail describe --tags` (also in System setup › Host).
- **Upgrade** (read the CHANGELOG first):
  ```sh
  cd ~/catto-mail
  git fetch --tags && git checkout vX.Y.Z
  infra/bin/smarthostctl prod check            # names any new setting to add to infra/.env
  infra/bin/smarthostctl prod upgrade X.Y.Z    # backup, build, recreate (migrations), checks
  ```
- **Rollback:**
  ```sh
  git checkout v<previous>
  infra/bin/smarthostctl prod rollback <previous> <the backup directory the upgrade made>
  ```
  It restores the previous images and the database from before the upgrade; anything recorded
  after it is lost, so pause first and roll back promptly.

**Releases page.** Every release from `v0.1.9` on has a page at
<https://github.com/rrcatto/catto-mail/releases> with its notes (from the CHANGELOG) and the
source as `catto-mail-vX.Y.Z.zip` and `.tar.gz`, with a `SHA256SUMS` file. To check a download:

```sh
sha256sum -c --ignore-missing SHA256SUMS
```

The archives are for reading, reviewing and archiving. The installer and the upgrade still use
the Git tag, which names exactly the same code. An unpacked archive's installer can be run with
`--version vX.Y.Z`; it then clones that tag.

## 13. What catto-mail cannot do for you

| Task | Where |
|---|---|
| Rent the VPS with a static IPv4 | VPS provider |
| Unblock outbound TCP 25 | VPS provider support |
| Set the PTR (reverse DNS) of the IP | VPS provider panel or support |
| Create the A, MX, SPF, DKIM, DMARC and verification records | your DNS provider (and each sending domain's DNS) |
| Create a DNS API token for Let's Encrypt (DNS-01) | your DNS provider |
| Provide an off-host backup target and keep the passphrase | your own second server or storage |
| Register feedback loops (complaint reports to `bounce@bounce.example.com`) | each mailbox provider's programme |
| Google Postmaster Tools (domain reputation, spam rate) | postmaster.google.com |
| Microsoft SNDS (IP reputation at Outlook.com) | sendersupport.olc.protection.outlook.com/snds |
| Decide retention periods | you, with your compliance owner (`APP_RETENTION_*`) |
| Decide consent and compliance (e.g. may an old list be asked again?) | you; recorded on each batch |
| Decide when to raise sending volume | you, on the evidence (§11) |
| Watch reputation | Operator › Abuse & reputation, provider tools |

## 14. Real-VPS acceptance checklist

```text
[ ] Ubuntu Server 26.04 LTS                       (installer step 1; Setup › Host)
[ ] exact release installed                       (Setup › Host: release)
[ ] rootless Podman working                       (installer step 8; Diagnostics: host.rootless)
[ ] cattomail service user working                (Setup › Host)
[ ] linger enabled                                (Diagnostics: boot.linger)
[ ] reboot startup proven                         (Setup › Reboot test)
[ ] PostgreSQL healthy                            (Diagnostics: database)
[ ] Symfony healthy                               (Diagnostics: app.web)
[ ] webhook worker healthy                        (Diagnostics: app.webhook.heartbeat)
[ ] validator healthy                             (Diagnostics: app.validator.*)
[ ] Go delivery daemon healthy                    (Diagnostics: app.delivery.heartbeat)
[ ] Postfix healthy                               (Diagnostics: container.postfix, postfix.*)
[ ] OpenDKIM healthy                              (Diagnostics: container.opendkim, dkim.*)
[ ] nginx healthy                                 (Diagnostics: container.nginx, ingress.*)
[ ] HTTPS working                                 (Diagnostics: tls.nginx-*)
[ ] inbound TCP 25 reachable                      (Diagnostics: dns.inbound-smtp-*)
[ ] outbound TCP 25 working                       (Diagnostics: postfix.outbound-smtp-*)
[ ] PTR correct                                   (Diagnostics: dns.ptr-*)
[ ] FCrDNS correct                                (Diagnostics: dns.forward-confirmed-*)
[ ] A records correct                             (Diagnostics: dns.a-*)
[ ] MX correct                                    (Diagnostics: dns.mx-*)
[ ] SPF correct                                   (Diagnostics: dns.spf-*)
[ ] DKIM correct                                  (Diagnostics: dkim.*; seed test dkim=pass)
[ ] DMARC present                                 (Diagnostics: dns.dmarc-*)
[ ] sending-domain verification passes            (client's Sending domains: verified)
[ ] ADMIN login works                             (§3)
[ ] backup works                                  (Diagnostics: backup.recent)
[ ] off-host backup configured                    (Diagnostics: backup.offhost)
[ ] seed delivery works                           (Setup › Seed test: PASS)
[ ] hard-bounce round trip works                  (Setup › Bounce test: PASS)
[ ] suppression works                             (Bounce test: suppression stage)
[ ] tracking pixel works                          (Seed test: open recorded)
[ ] click tracking works                          (Seed test: click recorded)
[ ] webhook works                                 (Setup › Webhook worker: test delivered)
[ ] held mode works                               (a send job waits while HELD)
[ ] emergency pause works                         (STOP SENDING EMAIL NOW, then resume)
[ ] controlled live activation works              (§11; the request's outcome)
```

## 15. Troubleshooting the installation

| Symptom | Do |
|---|---|
| A step FAILs | Read its *How to fix*; the log is `/var/log/catto-mail-install.log`; re-run after fixing. |
| The sign-in link expired | `sudo -u cattomail ~cattomail/catto-mail/infra/bin/smarthostctl prod admin-link` |
| The browser cannot reach the site | The A record (§4); inbound 443 at the provider; `prod status` shows the ingress socket. |
| "host agent never reported" | `systemctl --user status smarthost-host-agent.service` as cattomail; `prod install` installs it. |
| Port 25 refused | `prod preflight --section host` (the low-port setting); the provider's firewall. |
| Images fail to build | Disk space (`df -h`), network to the image registries; re-run. |

Everything else: Help › Troubleshooting in the dashboard, and the [runbook](runbook.md).

## 16. What was tested locally and what only a real VPS can prove

**Tested locally on Ubuntu Server 26.04** (`smarthostctl test installer --with-upgrade`: a systemd
container running the Ubuntu 26.04 image, installed from a local copy of the repository with a
test tag; no mail was sent):
- all 21 installer steps, from the packages to the sign-in link;
- a re-run that skipped the finished steps;
- the sign-in link redeemed through the ingress socket on port 443, landing on System setup;
- the host agent's checks, including client addresses through the ingress;
- a container restart standing in for a reboot: everything back in about 40 seconds, still HELD;
- the emergency stop pressed in the web application: the delivery daemon paused, the agent held
  the Postfix queue, and both released again after RESUME SENDING;
- `prod upgrade` to a new tag, and `prod rollback` to the backup the upgrade made.

**Tested locally in the production rehearsal** (`smarthostctl test phase8-rehearsal`, the
production topology without Internet egress) and in the test suites:
- the configuration rules, networks, volumes, units, the database, migrations and grants;
- held mode, every component, tracking and the webhook worker;
- backups, including encrypted and off-host copies, restore and the restore rehearsal;
- the host agent and its requests, diagnostics and the setup wizard;
- the bootstrap sign-in link and the re-permission page through nginx.

**Only a real VPS can prove:**
- the provider's port-25 policy, PTR and FCrDNS;
- public DNS records, and Let's Encrypt issuance with your DNS provider;
- outbound delivery and inbox placement;
- bounces and complaints from real receivers;
- the reboot of a real machine (a container restart keeps the kernel's boot time);
- the firewall applied on real network interfaces;
- reputation over time.
