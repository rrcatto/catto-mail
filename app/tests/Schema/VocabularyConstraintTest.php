<?php

declare(strict_types=1);

namespace App\Tests\Schema;

use App\Tests\Support\Db;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * conventions.md: a vocabulary value lives in the vocabulary file, the OpenAPI
 * enums, the Doctrine CHECK constraint and (here) the PHP enum. This proves the
 * migrated CHECK constraints and the PHP enums equal docs/contracts/status-vocabulary.yaml.
 */
final class VocabularyConstraintTest extends TestCase
{
    /** vocabulary key => list of table.column whose CHECK must list exactly its values */
    private const COLUMNS = [
        'client_status' => ['clients.status'], 'user_status' => ['users.status'], 'global_role' => ['users.global_role'],
        'client_membership_role' => ['client_memberships.role'], 'sending_domain_status' => ['sending_domains.status'],
        'dkim_status' => ['sending_domains.dkim_status'], 'validation_job_status' => ['validation_jobs.status'],
        'syntax_status' => ['validation_addresses.syntax_status'], 'domain_status' => ['validation_addresses.domain_status'],
        'smtp_status' => ['validation_addresses.smtp_status'], 'typo_reason_code' => ['validation_addresses.suggestion_reason_code'],
        'confidence_level' => ['validation_addresses.suggestion_confidence', 'validation_addresses.confidence'],
        'overall_classification' => ['validation_addresses.overall_classification'],
        'validation_processing_state' => ['validation_addresses.processing_state'],
        'validation_evidence_type' => ['validation_evidence.evidence_type'], 'message_class' => ['send_jobs.message_class'],
        'send_job_status' => ['send_jobs.status'], 'message_status' => ['messages.current_status'],
        'message_event_type' => ['message_events.event_type'], 'event_source' => ['message_events.event_source'],
        'failure_scope' => ['message_events.failure_scope'], 'dsn_classification' => ['unmatched_dsns.classification'],
        'unmatched_dsn_status' => ['unmatched_dsns.status'], 'suppression_scope_type' => ['suppressions.scope_type'],
        'suppression_reason' => ['suppressions.reason'], 'usage_type' => ['usage_records.usage_type'],
        'usage_reference_type' => ['usage_records.reference_type'], 'webhook_endpoint_status' => ['webhook_endpoints.status'],
        'webhook_event_type' => ['webhook_events.event_type', 'webhook_deliveries.event_type'],
        'webhook_subject_type' => ['webhook_events.subject_type'], 'webhook_delivery_status' => ['webhook_deliveries.status'],
        'audit_actor_type' => ['audit_log.actor_type'],
    ];

    /** @return array<string, list<string>> */
    private static function vocabulary(): array
    {
        $out = [];
        foreach (Yaml::parseFile(\dirname(__DIR__, 2).'/config/contracts/status-vocabulary.yaml') as $key => $v) {
            if (\is_array($v) && isset($v['values'])) {
                $out[$key] = array_map('strval', array_keys($v['values']));
            }
        }

        return $out;
    }

    public function testEveryVocabularyIsCoveredHere(): void
    {
        self::assertEqualsCanonicalizing(array_keys(self::vocabulary()), array_keys(self::COLUMNS));
    }

    public function testCheckConstraintsMatchTheVocabulary(): void
    {
        $defs = [];
        foreach (Db::owner()->fetchAllAssociative("SELECT cl.relname AS t, pg_get_constraintdef(co.oid) AS def
            FROM pg_constraint co JOIN pg_class cl ON cl.oid = co.conrelid WHERE co.contype = 'c'") as $r) {
            $defs[$r['t']][] = $r['def'];
        }
        foreach (self::vocabulary() as $key => $values) {
            foreach (self::COLUMNS[$key] as $tc) {
                [$table, $column] = explode('.', $tc);
                $found = null;
                foreach ($defs[$table] ?? [] as $def) {
                    // IN (...) is rendered as "= ANY (ARRAY[...])", a single value as "= '...'::text".
                    if (preg_match('/^CHECK \(\(?'.preg_quote($column, '/').' = ANY \(ARRAY\[(.*?)\]\)\)?\)$/', $def, $m)
                        || preg_match("/^CHECK \\(\\(?".preg_quote($column, '/')." = ('[^']*'::text)\\)?\\)$/", $def, $m)) {
                        preg_match_all("/'([^']*)'::text/", $m[1], $vals);
                        $found = $vals[1];
                    }
                }
                self::assertNotNull($found, "No enum CHECK for $tc");
                self::assertSame($values, $found, "CHECK of $tc differs from vocabulary $key");
            }
        }
    }

    public function testPhpEnumsMatchTheVocabulary(): void
    {
        foreach (self::vocabulary() as $key => $values) {
            $class = 'App\\Enum\\'.str_replace(' ', '', ucwords(str_replace(['_', '.'], ' ', $key)));
            self::assertTrue(enum_exists($class), "Missing enum $class");
            self::assertSame($values, array_column($class::cases(), 'value'), "$class differs from vocabulary $key");
        }
    }
}
