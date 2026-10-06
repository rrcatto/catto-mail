<?php

declare(strict_types=1);

namespace App\Webhook;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * SSRF boundary of webhook delivery (specification 2.8). Before every attempt the
 * endpoint URL is parsed, its host resolved, and every resolved address checked;
 * the request is then pinned to the checked address (HttpClient `resolve`), so a
 * DNS answer that changes between the check and the connection (rebinding)
 * cannot redirect it. Redirects are never followed.
 *
 * Refused: loopback, unspecified, private (RFC 1918), carrier-grade NAT,
 * link-local (including cloud metadata 169.254.169.254), multicast, broadcast,
 * reserved and documentation ranges, IPv6 loopback/unspecified/link-local/ULA/
 * multicast/documentation, and IPv4-mapped/NAT64/6to4 forms of refused IPv4.
 * A hostname with any refused address is refused.
 *
 * Development and test only: APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS lists exact host
 * names (the local client receiver fixture) that may resolve to private
 * addresses, and plain http is accepted. With SMARTHOST_ENV=production a
 * non-empty list is a startup error, and only https is accepted.
 */
final class WebhookTargetGuard
{
    /** @var list<string> */
    private array $allowedPrivateHosts;
    private bool $development;

    private const REFUSED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
    ];

    private const REFUSED_V6 = [
        '::/128', '::1/128', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8', '2001::/23',
    ];

    public function __construct(
        private readonly HostResolver $resolver,
        #[Autowire('%app.smarthost_env%')] string $smarthostEnv,
        #[Autowire('%app.webhook.allowed_private_hosts%')] string $allowedPrivateHosts,
    ) {
        $this->development = \in_array($smarthostEnv, ['development', 'test'], true);
        $hosts = array_values(array_filter(array_map(static fn (string $h): string => strtolower(trim($h)), explode(',', $allowedPrivateHosts)), 'strlen'));
        if (!$this->development && [] !== $hosts) {
            throw new \RuntimeException('APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS must be empty outside development and test.');
        }
        $this->allowedPrivateHosts = $hosts;
    }

    /**
     * @throws WebhookTargetRefused a permanent refusal (scheme, credentials, prohibited address)
     * @throws \RuntimeException     a temporary DNS failure (retryable)
     */
    public function check(string $url): WebhookTarget
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (false === $parts || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || 1 === preg_match('/[\x00-\x20\x7F]/', $url)) {
            throw new WebhookTargetRefused('The webhook URL is not a valid absolute URL without credentials.');
        }
        if (!('https' === $scheme || ('http' === $scheme && $this->development))) {
            throw new WebhookTargetRefused('Webhooks are delivered only to https URLs.');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $ips = false !== filter_var($host, \FILTER_VALIDATE_IP) ? [$host] : $this->resolver->resolve($host);
        if ([] === $ips) {
            throw new \RuntimeException("$host has no address.");
        }
        $mayBePrivate = $this->development && \in_array($host, $this->allowedPrivateHosts, true);
        foreach ($ips as $ip) {
            if (!$mayBePrivate && self::isRefused($ip)) {
                throw new WebhookTargetRefused('The webhook destination resolves to a non-public address.');
            }
        }

        return new WebhookTarget($url, $host, $ips[0]);
    }

    public static function isRefused(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if (false === $packed) {
            return true;
        }
        if (4 === \strlen($packed)) {
            return self::inAny($packed, self::REFUSED_V4);
        }
        // IPv4 embedded in IPv6: mapped (::ffff:0:0/96), NAT64 (64:ff9b::/96), compatible (::/96), 6to4 (2002::/16).
        foreach (['::ffff:0:0/96' => 12, '64:ff9b::/96' => 12, '::/96' => 12] as $cidr => $offset) {
            if (self::inCidr($packed, $cidr)) {
                return self::inAny(substr($packed, $offset, 4), self::REFUSED_V4) || '::/96' === $cidr;
            }
        }
        if (self::inCidr($packed, '2002::/16')) {
            return self::inAny(substr($packed, 2, 4), self::REFUSED_V4);
        }

        return self::inAny($packed, self::REFUSED_V6);
    }

    /** @param list<string> $cidrs */
    private static function inAny(string $packed, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $packed, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netPacked = inet_pton($net);
        if (false === $netPacked || \strlen($netPacked) !== \strlen($packed)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (0 !== $bytes && substr($packed, 0, $bytes) !== substr($netPacked, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if (0 === $rest) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (\ord($packed[$bytes]) & $mask) === (\ord($netPacked[$bytes]) & $mask);
    }
}
