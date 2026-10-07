<?php

declare(strict_types=1);

namespace App\Usage;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Entity\Client;
use App\Util\Clock;

/**
 * The usage export for an operator or a future billing integration (Phase 9). For a
 * client and a bounded period it lists, per usage category, the quantity metered from
 * usage_records, together with the reconciliation state and the billing statement of
 * exactly that period, if any. The output is deterministic (fixed category order, no
 * generation-dependent values besides `generated_at`) and reconcilable (the source is
 * named). Every export is audited (usage.exported); exports are operator data and need
 * PLATFORM.USAGE.EXPORT.
 */
final class UsageExporter
{
    public function __construct(
        private readonly UsageReporting $reporting,
        private readonly UsageReconciliation $reconciliation,
        private readonly BillingStatementService $statements,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function export(Client $client, UsagePeriod $period, AuditActor $actor): array
    {
        $clientId = $client->getId()->toRfc4122();
        $totals = $this->reporting->clientTotals($clientId, $period);
        $result = $this->reconciliation->reconcile($clientId, $period);
        $statement = $this->statements->current($client, $period);
        $categories = [];
        foreach ($totals as $type => $t) {
            $categories[] = ['usage_type' => $type, 'quantity' => $t['quantity'], 'usage_records' => $t['records'], 'source' => 'usage_records'];
        }
        $out = [
            'client_id' => $clientId,
            'client_name' => $client->getCompanyName(),
            'period' => $period->asArray(),
            'categories' => $categories,
            'reconciliation' => ['status' => $result['status'], 'findings_total' => $result['findings_total'], 'checked' => $result['checked']],
            'billing_statement' => null === $statement ? null : [
                'id' => $statement->getId()->toRfc4122(), 'status' => $statement->getStatus()->value,
                'reconciliation_status' => $statement->getReconciliationStatus()->value,
                'external_reference' => $statement->getExternalReference(),
            ],
            'generated_at' => Clock::rfc3339(Clock::now()),
        ];
        $this->audit->record($actor, 'usage.exported', 'client', $clientId, ['period' => $period->asArray(),
            'reconciliation_status' => $result['status'], 'totals' => array_column($categories, 'quantity', 'usage_type')]);

        return $out;
    }

    /** @param array<string, mixed> $export the result of export() */
    public static function csv(array $export): string
    {
        $lines = ['client_id,period_start,period_end,usage_type,quantity,usage_records,source,reconciliation_status,statement_status'];
        foreach ($export['categories'] as $c) {
            $lines[] = implode(',', [$export['client_id'], $export['period']['start'], $export['period']['end'], $c['usage_type'],
                $c['quantity'], $c['usage_records'], $c['source'], $export['reconciliation']['status'], $export['billing_statement']['status'] ?? '']);
        }

        return implode("\n", $lines)."\n";
    }
}
