<?php

declare(strict_types=1);

namespace App\Help;

/**
 * The administrator help and tutorials (specification 2.11): topics grouped for the index,
 * each a Twig page under templates/dashboard/help/topics/<slug>.html.twig. Pages link to
 * each other and the dashboard links to them in context (the `help` macro).
 */
final class HelpCatalog
{
    /** group => [slug => title] */
    public const GROUPS = [
        'Start here' => [
            'overview' => 'What catto-mail is (and is not)',
            'components' => 'The components, one by one',
            'architecture' => 'How an address and a message travel through the system',
            'installation' => 'Installation and the server',
            'reboot' => 'Automatic start after a reboot',
        ],
        'Validation and lists' => [
            'validation' => 'How validation works, stage by stage',
            'validation-results' => 'Understanding validation results',
            'uploading-lists' => 'Uploading lists (address batches)',
            'repermission' => 'Asking an old list for permission again',
            'suppressions' => 'Suppressions and unsubscribes',
        ],
        'Sending and results' => [
            'sending' => 'Sending, rollout stages and limits',
            'delivery-states' => 'Delivery states: what "delivered" really means',
            'tracking' => 'Open and click tracking (and its limits)',
            'bounces' => 'Bounces',
            'complaints' => 'Complaints',
            'emergency-pause' => 'Stopping all mail: held, live, paused, stopped',
        ],
        'Mail identity (DNS)' => [
            'dns' => 'DNS records, all together',
            'ptr' => 'PTR and forward-confirmed reverse DNS',
            'spf' => 'SPF',
            'dkim' => 'DKIM',
            'dmarc' => 'DMARC',
            'tls' => 'TLS certificates and Let\'s Encrypt',
        ],
        'The components in depth' => [
            'web-application' => 'The Catto-mail Web Application (Symfony)',
            'validator' => 'The Catto-mail Email Validator (Python)',
            'delivery-daemon' => 'The Catto-mail Delivery Daemon (Go)',
            'postfix' => 'Postfix in catto-mail',
            'database' => 'The PostgreSQL Database',
            'webhooks' => 'Webhooks and the Webhook Worker',
        ],
        'Clients and integration' => [
            'api-clients' => 'API clients and keys',
            'sending-domains' => 'Sending domains',
        ],
        'Operations' => [
            'backups' => 'Backups and restore',
            'logs' => 'Logs',
            'upgrades' => 'Upgrades and rollback',
            'security' => 'Security checks',
            'troubleshooting' => 'Troubleshooting',
        ],
    ];

    /** @return array<string, string> slug => title */
    public static function topics(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    public static function title(string $slug): ?string
    {
        return self::topics()[$slug] ?? null;
    }
}
