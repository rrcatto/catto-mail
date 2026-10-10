<?php

declare(strict_types=1);

namespace App\System;

/**
 * Plain-language explanations of the system checks (specification 2.11): what a check
 * proves, what to do when it is not PASS, and which help page explains the subject.
 * The check's own summary gives the specific finding; this adds the context an operator
 * who did not write the software needs.
 */
final class CheckCatalog
{
    /** key prefix => [what it checks, what to do, help topic]; the longest matching prefix wins */
    private const PREFIXES = [
        'host.operating-system' => ['The server runs the supported Ubuntu Server release.', 'Install on Ubuntu Server 26.04 LTS; other releases are untested.', 'installation'],
        'host.podman' => ['Podman, the container engine that runs every catto-mail component without root rights, is installed and recent enough.', 'Re-run the installer, or: sudo apt-get install podman.', 'components'],
        'host.lingering' => ['The service user\'s own systemd keeps running without anyone logged in, so catto-mail starts at boot.', 'As root: loginctl enable-linger cattomail.', 'reboot'],
        'host.net-ipv4-ip-unprivileged-port-start' => ['The kernel lets the unprivileged service user listen on ports 25 and 443.', 'Re-run the installer, or follow the FAIL line\'s command (installation guide, low ports).', 'installation'],
        'host.disk' => ['Free disk space for the database, the mail queue and backups.', 'Free space or enlarge the disk; below 10 % the database and Postfix can stop working.', 'troubleshooting'],
        'host.memory' => ['Available memory.', 'Add memory or stop other software on the server.', 'troubleshooting'],
        'host.clock' => ['The clock is synchronised (NTP). Wrong time breaks TLS, DKIM signatures and sign-in links.', 'Enable time synchronisation: sudo timedatectl set-ntp true.', 'troubleshooting'],
        'host.firewall' => ['A host firewall (nftables) limits inbound traffic to SSH, 25 and 443.', 'Review and apply the generated ruleset (installation guide, firewall).', 'security'],
        'boot.' => ['catto-mail comes back by itself after a reboot.', 'Check lingering, then: smarthostctl prod install (it links the services into the boot target).', 'reboot'],
        'runtime.database' => ['The PostgreSQL Database is running and accepts connections.', 'smarthostctl prod status, then smarthostctl prod logs postgres.', 'database'],
        'runtime.migrations-current' => ['The database schema is at the version this release expects.', 'smarthostctl prod migrate.', 'database'],
        'runtime.grants-current' => ['Each component\'s database role has exactly the rights it needs (least privilege).', 'smarthostctl prod migrate re-applies the grants.', 'database'],
        'runtime.service-health' => ['Every container reports healthy.', 'smarthostctl prod status, then the logs of the unhealthy component.', 'logs'],
        'runtime.images' => ['The container images of this release are built.', 'smarthostctl prod build.', 'upgrades'],
        'runtime.reputation-evaluation' => ['Client reputation is evaluated every 15 minutes.', 'Check the timer: smarthostctl prod machine systemctl list-timers.', 'bounces'],
        'container.' => ['The container is running and its own health check passes.', 'smarthostctl prod logs <component>; smarthostctl prod replace <component>.', 'logs'],
        'exposure.' => ['Only nginx and Postfix are reachable from outside, through the ingress socket; nothing else (database, PHP, workers) is exposed.', 'Do not publish container ports; re-run smarthostctl prod install.', 'security'],
        'ingress.' => ['nginx receives HTTPS and SMTP on the host\'s ports through the systemd socket, so every client\'s real address is preserved.', 'smarthostctl prod ingress-check; never start nginx with plain podman start.', 'components'],
        'tls.' => ['The HTTPS and SMTP certificates are valid, match their names and are trusted.', 'Obtain or renew the certificate (System setup › TLS, or smarthostctl prod tls acme).', 'tls'],
        'dns.a-' => ['The host name points at this server\'s public IPv4 address.', 'Create the A record at your DNS provider (System setup › DNS).', 'dns'],
        'dns.ptr' => ['Reverse DNS (PTR) of the sending IP names the mail host. Many receivers reject mail without it.', 'Set the PTR at your VPS provider, not in your DNS zone.', 'ptr'],
        'dns.forward-confirmed' => ['The PTR name resolves back to the same IP (FCrDNS).', 'Make the PTR name\'s A record point at this IP.', 'ptr'],
        'dns.mx' => ['Bounces reach this server: the bounce domain\'s MX points here.', 'Create the MX record of the bounce domain.', 'bounces'],
        'dns.spf' => ['SPF authorises this IP to send for the envelope domain and the EHLO name.', 'Publish the SPF TXT record shown in System setup › DNS.', 'spf'],
        'dns.dmarc' => ['The sending domain publishes a DMARC policy.', 'Publish a DMARC TXT record (start with p=none).', 'dmarc'],
        'dns.mailbox' => ['The administrator\'s address is a mailbox at a mail provider. Sign-in links are e-mailed, and catto-mail itself receives only bounce-domain mail, so it refuses mail for any other address.', 'Point the domain\'s MX at a mail provider (for example the registrar\'s e-mail forwarding). Until then, sign in with: smarthostctl prod admin-link.', 'dns'],
        'dns.inbound-smtp' => ['Port 25 of this server is reachable from the Internet (bounces and complaints arrive here).', 'Ask the VPS provider to allow inbound TCP 25; check the host firewall.', 'postfix'],
        'dkim.' => ['Each sending domain has an active DKIM key whose public half is published in DNS.', 'System setup › DKIM: generate, publish, test, activate.', 'dkim'],
        'postfix.port-25-is-not-an-open-relay' => ['Postfix refuses to relay mail for strangers. An open relay would be abused within hours.', 'Do not change the Postfix configuration; report a FAIL as a bug.', 'postfix'],
        'postfix.outbound' => ['This server can open SMTP connections to other mail servers on TCP 25.', 'Ask the VPS provider to unblock outbound TCP 25.', 'postfix'],
        'postfix.' => ['The Postfix Mail Transfer Agent is configured as catto-mail requires.', 'smarthostctl prod logs postfix.', 'postfix'],
        'delivery.' => ['The Catto-mail Delivery Daemon is alive and in the expected mode.', 'smarthostctl prod logs delivery.', 'delivery-daemon'],
        'config.' => ['The production configuration (infra/.env) satisfies the safety rules.', 'smarthostctl prod check names each problem.', 'installation'],
        'security.' => ['Secrets are protected and nothing unsafe is exposed.', 'Follow the check\'s instruction; see the security help page.', 'security'],
        'backup.' => ['Backups exist, are recent, are copied off the server and can be restored.', 'System setup › Backups; smarthostctl prod backup.', 'backups'],
        'app.database' => ['The Web Application reaches the database with its own least-privilege role.', 'smarthostctl prod logs app.', 'database'],
        'app.migrations' => ['Every migration of this release has run.', 'smarthostctl prod migrate.', 'database'],
        'app.web' => ['The Web Application answers requests.', 'smarthostctl prod logs app.', 'web-application'],
        'app.tracking' => ['The public open and click tracking endpoints answer correctly.', 'smarthostctl prod logs nginx and app.', 'tracking'],
        'app.validator' => ['The Catto-mail Email Validator claims and finishes a deterministic test job.', 'smarthostctl prod logs validator.', 'validator'],
        'app.delivery' => ['The Delivery Daemon reports in regularly and records its state.', 'smarthostctl prod logs delivery.', 'delivery-daemon'],
        'app.webhook' => ['The Catto-mail Webhook Worker reports in regularly.', 'smarthostctl prod logs webhook.', 'webhooks'],
        'app.agent' => ['The host agent (which runs the host-side checks and your requests) reports in.', 'smarthostctl prod machine systemctl status <instance>-host-agent.service.', 'components'],
        'app.bounce' => ['Bounces (DSNs) are being received and processed.', 'Send the bounce test (System setup › Bounce test).', 'bounces'],
        'app.emergency-stop' => ['Whether sending is stopped by the emergency control.', 'Lift it only after investigating: System › Delivery.', 'emergency-pause'],
    ];

    private const COMPONENTS = [
        'host' => ['The server itself.', 'See the check\'s summary.', 'installation'],
        'boot' => ['Automatic start after reboot.', 'See the reboot help page.', 'reboot'],
        'database' => ['PostgreSQL.', 'smarthostctl prod logs postgres.', 'database'],
        'web' => ['The Web Application.', 'smarthostctl prod logs app.', 'web-application'],
        'validator' => ['The Email Validator.', 'smarthostctl prod logs validator.', 'validator'],
        'delivery' => ['The Delivery Daemon.', 'smarthostctl prod logs delivery.', 'delivery-daemon'],
        'postfix' => ['Postfix.', 'smarthostctl prod logs postfix.', 'postfix'],
        'opendkim' => ['OpenDKIM.', 'smarthostctl prod logs opendkim.', 'dkim'],
        'dns' => ['Public DNS.', 'System setup › DNS.', 'dns'],
        'tls' => ['Certificates.', 'System setup › TLS.', 'tls'],
        'nginx' => ['The nginx Frontend.', 'smarthostctl prod logs nginx.', 'components'],
        'tracking' => ['Tracking.', 'smarthostctl prod logs app.', 'tracking'],
        'webhook' => ['The Webhook Worker.', 'smarthostctl prod logs webhook.', 'webhooks'],
        'bounce' => ['The bounce path.', 'System setup › Bounce test.', 'bounces'],
        'backup' => ['Backups.', 'System setup › Backups.', 'backups'],
        'security' => ['Security.', 'See the security help page.', 'security'],
    ];

    /** @return array{what: string, fix: string, topic: string} */
    public static function explain(string $checkKey, string $component): array
    {
        $best = null;
        foreach (self::PREFIXES as $prefix => $row) {
            if (str_starts_with($checkKey, $prefix) && (null === $best || \strlen($prefix) > \strlen($best[0]))) {
                $best = [$prefix, $row];
            }
        }
        $row = null !== $best ? $best[1] : (self::COMPONENTS[$component] ?? ['', '', 'troubleshooting']);

        return ['what' => $row[0], 'fix' => $row[1], 'topic' => $row[2]];
    }
}
