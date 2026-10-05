<?php

declare(strict_types=1);

namespace App\Tenant;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Doctrine SQL filter that confines every ORM read to one client (defence in
 * depth behind App\Tenant\TenantScope; schema.md §3 scoping paths).
 *
 * Enabled for authenticated API requests by App\Tenant\TenantFilterSubscriber.
 * Tables are classified explicitly; a table that is not listed is invisible
 * (deny by default), so a new table cannot leak by omission. Raw DBAL queries are
 * not filtered and must carry their own client condition.
 */
final class TenantFilter extends SQLFilter
{
    public const PARAMETER = 'client_id';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $a = $targetTableAlias;
        $c = $this->getParameter(self::PARAMETER);

        return match ($targetEntity->getTableName()) {
            'clients' => "$a.id = $c",
            'client_memberships', 'sending_domains', 'api_keys', 'validation_jobs', 'send_jobs', 'usage_records',
            'webhook_endpoints', 'webhook_events', 'webhook_deliveries', 'domain_reputation',
            'global_suppression_requests' => "$a.client_id = $c",
            // A client sees its own client-scoped rows and the global opt-outs it reported;
            // other global suppressions (system, operator, other reporters) are not tenant data (D-30).
            'suppressions' => "($a.client_id = $c OR ($a.client_id IS NULL AND $a.source_client_id = $c))",
            'validation_addresses' => "$a.job_id IN (SELECT vj.id FROM validation_jobs vj WHERE vj.client_id = $c)",
            'validation_evidence' => "$a.validation_address_id IN (SELECT va.id FROM validation_addresses va JOIN validation_jobs vj ON vj.id = va.job_id WHERE vj.client_id = $c)",
            'send_job_recipient_batches', 'send_job_recipients', 'messages' => "$a.send_job_id IN (SELECT sj.id FROM send_jobs sj WHERE sj.client_id = $c)",
            'message_links', 'message_events' => "$a.message_id IN (SELECT m.id FROM messages m JOIN send_jobs sj ON sj.id = m.send_job_id WHERE sj.client_id = $c)",
            'disposable_domains' => '',
            default => '1 = 0', // users, audit_log, unmatched_dsns, delivery_ingest_cursors, anything new
        };
    }
}
