<?php

declare(strict_types=1);

namespace App\Entity;

use App\Tenant\TenantOwned;
use App\Util\Clock;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-client operational limits (`client_limits`, Phase 9). NULL = no client-specific
 * limit. The effective limit is min(client limit, installation ceiling); see
 * App\Client\ClientLimitPolicy. Changed only through App\Client\ClientLimitAdministration (audited).
 */
#[ORM\Entity]
#[ORM\Table(name: 'client_limits')]
class ClientLimits implements TenantOwned
{
    /** Field name => column, in display order. */
    public const FIELDS = [
        'apiRequestsPerMinute' => 'api_requests_per_minute',
        'validationJobsPerDay' => 'validation_jobs_per_day',
        'validationAddressesPerDay' => 'validation_addresses_per_day',
        'validationAddressesPerMonth' => 'validation_addresses_per_month',
        'sendJobsPerDay' => 'send_jobs_per_day',
        'sendRecipientsPerDay' => 'send_recipients_per_day',
        'sendRecipientsPerMonth' => 'send_recipients_per_month',
        'maxRecipientsPerSendJob' => 'max_recipients_per_send_job',
        'maxApiKeys' => 'max_api_keys',
        'maxWebhookEndpoints' => 'max_webhook_endpoints',
        'maxSendingDomains' => 'max_sending_domains',
    ];

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $apiRequestsPerMinute = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $validationJobsPerDay = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private int|string|null $validationAddressesPerDay = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private int|string|null $validationAddressesPerMonth = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sendJobsPerDay = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private int|string|null $sendRecipientsPerDay = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private int|string|null $sendRecipientsPerMonth = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxRecipientsPerSendJob = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxApiKeys = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxWebhookEndpoints = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxSendingDomains = null;

    #[ORM\Column(type: 'timestamptz')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(name: 'client_id', nullable: false)]
        private Client $client,
    ) {
        $this->updatedAt = Clock::now();
    }

    public function getClient(): Client { return $this->client; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function get(string $field): ?int
    {
        if (!isset(self::FIELDS[$field])) {
            throw new \InvalidArgumentException("Unknown limit $field.");
        }
        $v = $this->{$field};

        return null === $v ? null : (int) $v;
    }

    public function set(string $field, ?int $value): void
    {
        if (!isset(self::FIELDS[$field])) {
            throw new \InvalidArgumentException("Unknown limit $field.");
        }
        $this->{$field} = $value;
        $this->updatedAt = Clock::now();
    }

    /** @return array<string, ?int> column => value */
    public function asArray(): array
    {
        $out = [];
        foreach (self::FIELDS as $field => $column) {
            $out[$column] = $this->get($field);
        }

        return $out;
    }
}
