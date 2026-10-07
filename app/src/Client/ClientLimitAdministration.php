<?php

declare(strict_types=1);

namespace App\Client;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\ClientLimits;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Changes a client's operational limits (client_limits, Phase 9). A value of null
 * removes the client-specific limit. A value above an installation ceiling is refused
 * (ClientLimitPolicy::assertWithinCeiling). Audited as client.limits_changed with the
 * changed columns before and after, and the operator's reason.
 */
final class ClientLimitAdministration
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly ClientLimitPolicy $policy,
    ) {
    }

    /**
     * @param array<string, ?int> $values column => value (null = no client limit); unknown columns are refused
     *
     * @return array<string, array{before: ?int, after: ?int}> the changes made
     */
    public function set(Client $client, array $values, AuditActor $actor, string $note): array
    {
        $fields = array_flip(ClientLimits::FIELDS);
        foreach ($values as $column => $value) {
            if (!isset($fields[$column])) {
                throw new DomainRuleViolation("Unknown limit $column.");
            }
            $minimum = 'api_requests_per_minute' === $column || 'max_recipients_per_send_job' === $column ? 1 : 0;
            if (null !== $value && $value < $minimum) {
                throw new DomainRuleViolation(\sprintf('%s must be at least %d.', $column, $minimum));
            }
            if ('max_recipients_per_send_job' === $column && null !== $value && $value > 10000) {
                throw new DomainRuleViolation('max_recipients_per_send_job may not exceed the contract ceiling of 10000.');
            }
            $this->policy->assertWithinCeiling($column, $value);
        }
        $limits = $this->em->find(ClientLimits::class, $client->getId()) ?? new ClientLimits($client);
        $changes = [];
        foreach ($values as $column => $value) {
            $before = $limits->get($fields[$column]);
            if ($before !== $value) {
                $changes[$column] = ['before' => $before, 'after' => $value];
            }
        }
        if ([] === $changes) {
            return [];
        }
        if (mb_strlen(trim($note)) < 3) {
            throw new DomainRuleViolation('Record a reason (at least 3 characters) for changing client limits.');
        }
        $this->em->wrapInTransaction(function () use ($limits, $changes, $fields, $client, $actor, $note): void {
            foreach ($changes as $column => $change) {
                $limits->set($fields[$column], $change['after']);
            }
            $this->em->persist($limits);
            $this->em->flush();
            $this->audit->record($actor, 'client.limits_changed', 'client', $client->getId()->toRfc4122(),
                ['changes' => $changes, 'note' => trim($note)]);
        });

        return $changes;
    }
}
