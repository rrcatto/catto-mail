<?php

declare(strict_types=1);

namespace App\Client;

use App\Config\Limits;
use App\Entity\Client;
use App\Enum\QuotaMetric;
use App\Enum\QuotaPeriod;
use Doctrine\DBAL\Connection;

/**
 * Effective per-client limits (Phase 9, specification 2.10 `client_limits`).
 *
 * A client's own limit (client_limits, NULL = none) can only lower an installation
 * ceiling, never raise it: the effective value is min(client limit, ceiling). The
 * ceilings are the contract and environment limits:
 *
 *   API requests per minute     APP_API_RATE_LIMIT_PER_MINUTE (all keys of the client
 *                               together); a throttled client also
 *                               APP_THROTTLED_CLIENT_API_RATE_PER_MINUTE
 *   recipients per send job     APP_SEND_JOB_MAX_RECIPIENTS (contract ceiling 10000)
 *   usable API keys             APP_CLIENT_API_KEY_LIMIT
 *   webhook endpoints           APP_CLIENT_WEBHOOK_ENDPOINT_LIMIT
 *   sending domains             APP_CLIENT_SENDING_DOMAIN_LIMIT
 *
 * Volume quotas (validation jobs/addresses, send jobs/recipients per calendar day or month in APP_TIMEZONE)
 * have no installation ceiling: commercial plans are an operator decision, so they
 * apply only where an operator set them for the client (App\Client\QuotaEnforcer).
 */
final class ClientLimitPolicy
{
    /** Quota (metric, period) => client_limits column. */
    public const QUOTAS = [
        'validation_jobs|day' => 'validation_jobs_per_day',
        'validation_addresses|day' => 'validation_addresses_per_day',
        'validation_addresses|month' => 'validation_addresses_per_month',
        'send_jobs|day' => 'send_jobs_per_day',
        'send_recipients|day' => 'send_recipients_per_day',
        'send_recipients|month' => 'send_recipients_per_month',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly Limits $limits,
        private readonly int $apiRateLimitPerMinute,
        private readonly int $throttledApiRatePerMinute,
        private readonly int $apiKeyLimit,
        private readonly int $webhookEndpointLimit,
        private readonly int $sendingDomainLimit,
    ) {
        foreach (['APP_THROTTLED_CLIENT_API_RATE_PER_MINUTE' => $throttledApiRatePerMinute, 'APP_API_RATE_LIMIT_PER_MINUTE' => $apiRateLimitPerMinute] as $name => $v) {
            if ($v < 1) {
                throw new \InvalidArgumentException("$name must be a positive integer.");
            }
        }
        foreach (['APP_CLIENT_API_KEY_LIMIT' => $apiKeyLimit, 'APP_CLIENT_WEBHOOK_ENDPOINT_LIMIT' => $webhookEndpointLimit,
            'APP_CLIENT_SENDING_DOMAIN_LIMIT' => $sendingDomainLimit] as $name => $v) {
            if ($v < 0) {
                throw new \InvalidArgumentException("$name must not be negative.");
            }
        }
    }

    /** @return array<string, ?int> the client's own limits (column => value; NULL = none) */
    public function clientLimits(Client|string $client): array
    {
        $id = $client instanceof Client ? $client->getId()->toRfc4122() : $client;
        $row = $this->connection->fetchAssociative('SELECT * FROM client_limits WHERE client_id = ?', [$id]);
        $out = [];
        foreach (\App\Entity\ClientLimits::FIELDS as $column) {
            $out[$column] = false === $row || null === $row[$column] ? null : (int) $row[$column];
        }

        return $out;
    }

    /** The installation ceiling of a limit column, or null when it has none. */
    public function ceiling(string $column): ?int
    {
        return match ($column) {
            'api_requests_per_minute' => $this->apiRateLimitPerMinute,
            'max_recipients_per_send_job' => $this->limits->maxRecipientsPerJob,
            'max_api_keys' => $this->apiKeyLimit,
            'max_webhook_endpoints' => $this->webhookEndpointLimit,
            'max_sending_domains' => $this->sendingDomainLimit,
            default => null,
        };
    }

    /**
     * Effective limit of every column for the client: min(client limit, ceiling);
     * null = unlimited (a volume quota nobody set).
     *
     * @return array<string, ?int>
     */
    public function effective(Client $client): array
    {
        $out = [];
        foreach ($this->clientLimits($client) as $column => $value) {
            $out[$column] = self::min($value, $this->ceiling($column));
        }
        $out['api_requests_per_minute'] = $this->apiRequestsPerMinute($client, $out['api_requests_per_minute']);

        return $out;
    }

    public function apiRequestsPerMinute(Client $client, ?int $effective = null): int
    {
        $rate = $effective ?? self::min($this->clientLimits($client)['api_requests_per_minute'], $this->apiRateLimitPerMinute) ?? $this->apiRateLimitPerMinute;

        return $client->isThrottled() ? min($rate, $this->throttledApiRatePerMinute) : $rate;
    }

    public function maxRecipientsPerSendJob(Client $client): int
    {
        return (int) self::min($this->clientLimits($client)['max_recipients_per_send_job'], $this->limits->maxRecipientsPerJob);
    }

    public function maxApiKeys(Client $client): int
    {
        return (int) self::min($this->clientLimits($client)['max_api_keys'], $this->apiKeyLimit);
    }

    public function maxWebhookEndpoints(Client $client): int
    {
        return (int) self::min($this->clientLimits($client)['max_webhook_endpoints'], $this->webhookEndpointLimit);
    }

    public function maxSendingDomains(Client $client): int
    {
        return (int) self::min($this->clientLimits($client)['max_sending_domains'], $this->sendingDomainLimit);
    }

    public function quota(Client $client, QuotaMetric $metric, QuotaPeriod $period): ?int
    {
        $column = self::QUOTAS[$metric->value.'|'.$period->value] ?? null;

        return null === $column ? null : $this->clientLimits($client)[$column];
    }

    /** Rejects a client limit above its ceiling (the ceiling may only be lowered). */
    public function assertWithinCeiling(string $column, ?int $value): void
    {
        $ceiling = $this->ceiling($column);
        if (null !== $value && null !== $ceiling && $value > $ceiling) {
            throw new \App\Domain\DomainRuleViolation(\sprintf(
                '%s may not exceed the installation ceiling of %d (a client limit may only lower it).', $column, $ceiling));
        }
    }

    private static function min(?int $a, ?int $b): ?int
    {
        return null === $a ? $b : (null === $b ? $a : min($a, $b));
    }
}
