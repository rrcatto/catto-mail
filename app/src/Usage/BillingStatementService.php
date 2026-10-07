<?php

declare(strict_types=1);

namespace App\Usage;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Entity\BillingStatement;
use App\Entity\BillingStatementLine;
use App\Entity\Client;
use App\Enum\BillingReconciliationStatus;
use App\Enum\BillingStatementStatus;
use App\Enum\UsageType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The provider-neutral billing boundary (Phase 9, specification 2.10 `usage_and_billing`).
 *
 * Turns metered usage into billable records without choosing a payment provider and
 * without prices: a statement holds, per client and period, the quantity of every usage
 * type, the reconciliation result, and its hand-over state.
 *
 *   prepare      draft with totals from usage_records and a fresh reconciliation;
 *                re-preparing a draft recomputes it
 *   finalize     draft -> finalized: refused unless the reconciliation is consistent and
 *                the totals still equal the usage records
 *   markExported finalized -> exported, with the external billing system's reference
 *   void         draft or finalized -> void (with a reason); the period can be prepared again
 *
 * Pricing, invoices and payment belong to the external billing system that consumes the
 * export (App\Usage\UsageExporter). Every change is audited.
 */
final class BillingStatementService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly UsageReporting $reporting,
        private readonly UsageReconciliation $reconciliation,
    ) {
    }

    public function prepare(Client $client, UsagePeriod $period, AuditActor $actor): BillingStatement
    {
        $clientId = $client->getId()->toRfc4122();
        if ($period->end > \App\Util\Clock::now()) {
            throw new DomainRuleViolation('A statement can only be prepared for a period that has ended.');
        }
        $existing = $this->current($client, $period);
        if (null !== $existing && BillingStatementStatus::Draft !== $existing->getStatus()) {
            throw new DomainRuleViolation(\sprintf('The period already has a %s statement (%s); void it first to prepare it again.',
                $existing->getStatus()->value, $existing->getId()));
        }
        $totals = $this->reporting->clientTotals($clientId, $period);
        $result = $this->reconciliation->reconcile($clientId, $period);

        return $this->em->wrapInTransaction(function () use ($client, $period, $totals, $result, $actor, $clientId): BillingStatement {
            $this->em->lock($client, LockMode::PESSIMISTIC_WRITE);
            $statement = $this->current($client, $period);
            if (null !== $statement && BillingStatementStatus::Draft !== $statement->getStatus()) {
                throw new DomainRuleViolation(\sprintf('The period already has a %s statement (%s); void it first to prepare it again.',
                    $statement->getStatus()->value, $statement->getId()));
            }
            $status = BillingReconciliationStatus::from($result['status']);
            $lines = [];
            if (null === $statement) {
                $statement = new BillingStatement($client, $period->start, $period->end, $status, $result);
                $this->em->persist($statement);
            } else {
                $statement->recordReconciliation($status, $result);
                foreach ($this->lines($statement) as $line) {
                    $lines[$line->getUsageType()->value] = $line;
                }
            }
            foreach (UsageType::cases() as $type) {
                $quantity = $totals[$type->value]['quantity'];
                isset($lines[$type->value]) ? $lines[$type->value]->setQuantity($quantity)
                    : $this->em->persist(new BillingStatementLine($statement, $type, $quantity));
            }
            $this->em->flush();
            $this->audit->record($actor, 'billing_statement.prepared', 'billing_statement', $statement->getId()->toRfc4122(), [
                'client_id' => $clientId, 'period' => $period->asArray(), 'reconciliation_status' => $status->value,
                'totals' => array_map(static fn (array $t): int => $t['quantity'], $totals)]);

            return $statement;
        });
    }

    public function finalize(BillingStatement $statement, AuditActor $actor): void
    {
        if (BillingStatementStatus::Draft !== $statement->getStatus()) {
            throw new DomainRuleViolation(\sprintf('The statement is %s; this needs a draft statement.', $statement->getStatus()->value));
        }
        $period = $this->periodOf($statement);
        $clientId = $statement->getClient()->getId()->toRfc4122();
        $result = $this->reconciliation->reconcile($clientId, $period);
        $totals = $this->reporting->clientTotals($clientId, $period);
        $changed = [];
        foreach ($this->lines($statement) as $line) {
            if ($line->getQuantity() !== $totals[$line->getUsageType()->value]['quantity']) {
                $changed[] = $line->getUsageType()->value;
            }
        }
        // The fresh reconciliation is kept on the statement whatever the outcome.
        $this->em->wrapInTransaction(function () use ($statement, $result): void {
            $statement->recordReconciliation(BillingReconciliationStatus::from($result['status']), $result);
            $this->em->flush();
        });
        if ('consistent' !== $result['status'] || [] !== $changed) {
            throw new DomainRuleViolation('The statement cannot be finalized: '.([] !== $changed
                ? 'its totals no longer equal the usage records ('.implode(', ', $changed).'); prepare it again'
                : \sprintf('the reconciliation found %d inconsistencies (see the statement)', $result['findings_total'])).'.');
        }
        $this->transition($statement, BillingStatementStatus::Draft, $actor, static function () use ($statement): array {
            $statement->finalize();

            return ['action' => 'billing_statement.finalized', 'detail' => []];
        });
    }

    public function markExported(BillingStatement $statement, string $externalReference, AuditActor $actor): void
    {
        $externalReference = trim($externalReference);
        if ('' === $externalReference || mb_strlen($externalReference) > 255) {
            throw new DomainRuleViolation('The external billing reference has 1-255 characters.');
        }
        $this->transition($statement, BillingStatementStatus::Finalized, $actor, static function () use ($statement, $externalReference): array {
            $statement->markExported($externalReference);

            return ['action' => 'billing_statement.exported', 'detail' => ['external_reference' => $externalReference]];
        });
    }

    public function void(BillingStatement $statement, string $note, AuditActor $actor): void
    {
        if (mb_strlen(trim($note)) < 3) {
            throw new DomainRuleViolation('Record a reason (at least 3 characters) for voiding a statement.');
        }
        if (!\in_array($statement->getStatus(), [BillingStatementStatus::Draft, BillingStatementStatus::Finalized], true)) {
            throw new DomainRuleViolation(\sprintf('A %s statement cannot be voided.', $statement->getStatus()->value));
        }
        $this->transition($statement, $statement->getStatus(), $actor, static function () use ($statement, $note): array {
            $statement->void();

            return ['action' => 'billing_statement.voided', 'detail' => ['note' => trim($note)]];
        });
    }

    /** @return list<BillingStatementLine> */
    public function lines(BillingStatement $statement): array
    {
        return $this->em->getRepository(BillingStatementLine::class)->findBy(['statement' => $statement], ['usageType' => 'ASC']);
    }

    public function current(Client $client, UsagePeriod $period): ?BillingStatement
    {
        return $this->em->createQuery(
            'SELECT s FROM App\Entity\BillingStatement s WHERE s.client = :c AND s.periodStart = :ps AND s.periodEnd = :pe AND s.status <> :void')
            ->setParameter('c', $client->getId(), 'uuid')->setParameter('ps', $period->start, 'date_immutable')
            ->setParameter('pe', $period->end, 'date_immutable')->setParameter('void', BillingStatementStatus::Void->value)
            ->getOneOrNullResult();
    }

    public function periodOf(BillingStatement $statement): UsagePeriod
    {
        return UsagePeriod::custom($statement->getPeriodStart()->format('Y-m-d'), $statement->getPeriodEnd()->format('Y-m-d'));
    }

    /** @param callable(): array{action: string, detail: array<string, mixed>} $change */
    private function transition(BillingStatement $statement, BillingStatementStatus $required, AuditActor $actor, callable $change): void
    {
        if ($statement->getStatus() !== $required) {
            throw new DomainRuleViolation(\sprintf('The statement is %s; this needs a %s statement.', $statement->getStatus()->value, $required->value));
        }
        $this->em->wrapInTransaction(function () use ($statement, $required, $actor, $change): void {
            $this->em->refresh($statement, LockMode::PESSIMISTIC_WRITE);
            if ($statement->getStatus() !== $required) {
                throw new DomainRuleViolation(\sprintf('The statement is %s; this needs a %s statement.', $statement->getStatus()->value, $required->value));
            }
            ['action' => $action, 'detail' => $detail] = $change();
            $this->em->flush();
            $this->audit->record($actor, $action, 'billing_statement', $statement->getId()->toRfc4122(),
                ['client_id' => $statement->getClient()->getId()->toRfc4122()] + $detail);
        });
    }
}
