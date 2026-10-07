<?php

declare(strict_types=1);

namespace App\AddressBatch;

/**
 * The separate state dimensions of a batch address (specification 2.11;
 * status-vocabulary.yaml operator_address_states). One address can at the same time be
 * valid, unconfirmed, eligible for a seed test, later hard bounced and globally
 * suppressed, so there is no single "status": each dimension is derived on its own from
 * the authoritative rows (validation result, decisions, consent evidence, suppressions,
 * messages and their events).
 */
final class EntryStates
{
    /** The validator's evidence mapped to the operator-facing result. */
    public const VALIDATION_RESULTS = [
        'pending' => 'Pending', 'valid' => 'Valid', 'invalid' => 'Invalid', 'risky' => 'Risky', 'unknown' => 'Unknown',
        'temporary_failure' => 'Temporary failure',
    ];

    public const ELIGIBILITY = [
        'eligible' => 'Eligible', 'pending_review' => 'Needs review', 'blocked_invalid' => 'Blocked: invalid',
        'blocked_hard_bounce' => 'Blocked: hard bounce', 'blocked_complaint' => 'Blocked: complaint',
        'blocked_suppressed' => 'Blocked: suppressed', 'blocked_unsubscribed' => 'Blocked: unsubscribed', 'excluded' => 'Excluded',
    ];

    public const CONSENT = [
        'unknown' => 'Unknown (not asked)', 'unconfirmed' => 'Asked, no answer yet', 'confirmed' => 'Confirmed',
        'unsubscribed' => 'Unsubscribed from this list', 'global_opt_out' => 'Global opt-out',
    ];

    public const DELIVERY = [
        'not_sent' => 'Not sent', 'waiting' => 'In a send job, not yet started', 'created' => 'Created', 'queued' => 'Queued in the delivery daemon',
        'submitted' => 'Submitted to Postfix', 'deferred' => 'Deferred (retrying)', 'remote_accepted' => 'Remote accepted',
        'soft_bounced' => 'Soft bounced', 'hard_bounced' => 'Hard bounced', 'complained' => 'Complained', 'suppressed' => 'Suppressed (not sent)',
        'failed' => 'Failed', 'outcome_unknown' => 'Outcome unknown',
    ];

    public const ENGAGEMENT = ['not_opened' => 'No recorded open', 'open_observed' => 'Open observed', 'clicked' => 'Clicked'];

    /**
     * One row per entry of batch :batch with every dimension. Parameters: batch (named).
     * Suppressions: an active address suppression of the batch's client or global, or an
     * active domain suppression of the address's domain (the same rule the delivery daemon
     * applies before every submission, which stays authoritative).
     */
    public static function sql(string $where = 'true', string $order = 'row_number, id'): string
    {
        return <<<SQL
            WITH b AS (SELECT id, client_id FROM address_batches WHERE id = :batch),
            base AS (
              SELECT e.id, e.batch_id, e.row_number, e.original_value, e.normalized_address, e.outcome, e.outcome_detail,
                     e.duplicate_of_entry_id, e.corrects_entry_id, e.review_decision, e.typo_decision, e.consent_state,
                     e.consent_changed_at, e.created_at,
                     va.id AS validation_address_id, va.processing_state, va.overall_classification, va.confidence,
                     va.diagnostic_code, va.diagnostic_text, va.syntax_status, va.domain_status, va.smtp_status,
                     va.is_role, va.is_disposable, va.is_catch_all_or_accept_all, va.is_domain_typo_suspected,
                     va.suggested_address, va.suggestion_confidence, va.checked_at,
                     sup.reason AS suppression_reason, sup.scope_type AS suppression_scope, sup.created_at AS suppressed_at,
                     msg.id AS message_id, msg.current_status AS message_status, msg.created_at AS message_created_at,
                     rcpt.send_job_id AS send_job_id,
                     EXISTS (SELECT 1 FROM message_events me WHERE me.message_id = msg.id AND me.event_type = 'open_recorded') AS opened,
                     EXISTS (SELECT 1 FROM message_events me WHERE me.message_id = msg.id AND me.event_type = 'click_recorded') AS clicked
                FROM address_batch_entries e
                JOIN b ON b.id = e.batch_id
                LEFT JOIN validation_addresses va ON va.id = e.validation_address_id
                LEFT JOIN LATERAL (
                  SELECT s.reason, s.scope_type, s.created_at FROM suppressions s
                   WHERE e.normalized_address IS NOT NULL AND s.lifted_at IS NULL AND (s.expires_at IS NULL OR s.expires_at > now())
                     AND (s.client_id IS NULL OR s.client_id = b.client_id)
                     AND ((s.scope_type = 'address' AND s.address_or_domain = e.normalized_address)
                       OR (s.scope_type = 'domain' AND s.address_or_domain = substring(e.normalized_address FROM '@([^@]+)\$')))
                   ORDER BY CASE s.reason WHEN 'complaint' THEN 1 WHEN 'hard_bounce' THEN 2 WHEN 'recipient_global_opt_out' THEN 3 ELSE 4 END
                   LIMIT 1) sup ON true
                LEFT JOIN LATERAL (
                  SELECT r.send_job_id, r.id FROM address_batch_sends bs
                    JOIN send_job_recipients r ON r.send_job_id = bs.send_job_id AND r.normalized_address = e.normalized_address
                   WHERE bs.batch_id = e.batch_id AND bs.stage <> 'seed' AND e.outcome = 'imported'
                   ORDER BY bs.created_at DESC LIMIT 1) rcpt ON true
                LEFT JOIN messages msg ON msg.send_job_recipient_id = rcpt.id
               WHERE {$where}
            ),
            v AS (
              SELECT base.*,
                     CASE WHEN outcome <> 'imported' THEN NULL
                          WHEN validation_address_id IS NULL OR processing_state <> 'done' THEN 'pending'
                          WHEN overall_classification IN ('deliverable', 'probably_deliverable') THEN 'valid'
                          WHEN overall_classification = 'undeliverable' THEN 'invalid'
                          WHEN overall_classification = 'risky' THEN 'risky'
                          WHEN overall_classification = 'temporarily_unverifiable' THEN 'temporary_failure'
                          ELSE 'unknown' END AS validation_result
                FROM base
            )
            SELECT v.*,
                   CASE WHEN outcome = 'malformed' THEN 'blocked_invalid'
                        WHEN outcome = 'duplicate' OR typo_decision = 'accepted' THEN 'excluded'
                        WHEN suppression_reason = 'hard_bounce' THEN 'blocked_hard_bounce'
                        WHEN suppression_reason = 'complaint' THEN 'blocked_complaint'
                        WHEN suppression_reason IS NOT NULL OR consent_state = 'global_opt_out' THEN 'blocked_suppressed'
                        WHEN consent_state = 'unsubscribed' THEN 'blocked_unsubscribed'
                        WHEN review_decision = 'exclude' THEN 'excluded'
                        WHEN validation_result = 'invalid' THEN 'blocked_invalid'
                        WHEN validation_result = 'pending' THEN 'pending_review'
                        WHEN suggested_address IS NOT NULL AND typo_decision IS NULL THEN 'pending_review'
                        WHEN validation_result = 'valid' THEN 'eligible'
                        WHEN review_decision = 'include' THEN 'eligible'
                        ELSE 'pending_review' END AS eligibility,
                   CASE WHEN message_status IS NOT NULL THEN message_status
                        WHEN send_job_id IS NOT NULL THEN 'waiting' ELSE 'not_sent' END AS delivery_state,
                   CASE WHEN clicked THEN 'clicked' WHEN opened THEN 'open_observed' ELSE 'not_opened' END AS engagement
              FROM v
             ORDER BY {$order}
            SQL;
    }

    /** Human explanation of a validation result, from the validator's evidence. */
    public static function explain(array $e): string
    {
        if ('malformed' === $e['outcome']) {
            return 'Not an e-mail address: '.($e['outcome_detail'] ?? 'malformed input').' It was not validated.';
        }
        if ('duplicate' === $e['outcome']) {
            return (string) ($e['outcome_detail'] ?? 'Duplicate of an earlier row.').' Only the first row is validated and sent to.';
        }
        if ('pending' === $e['validation_result']) {
            return null === $e['validation_address_id'] ? 'Not validated yet: start the validation.' : 'Being validated (or waiting to retry a temporary failure).';
        }
        $parts = [];
        if (null !== $e['diagnostic_text'] && '' !== $e['diagnostic_text']) {
            $parts[] = rtrim((string) $e['diagnostic_text'], '.').'.';
        }
        if ('valid' === $e['validation_result']) {
            $parts[] = 'probably_deliverable' === $e['overall_classification']
                ? 'Valid means the domain accepts mail and nothing contradicts the mailbox; it is not proof that a person reads it.'
                : 'The mailbox accepted a probe and the domain rejects unknown addresses: still evidence, not proof of a reader.';
        }
        if ($e['is_catch_all_or_accept_all']) {
            $parts[] = 'The server accepts any address on this domain.';
        }
        if ($e['is_disposable']) {
            $parts[] = 'Disposable-address domain.';
        }
        if ($e['is_role']) {
            $parts[] = 'Role account (e.g. info@, sales@): usually several people or none.';
        }
        if (null !== $e['suggested_address']) {
            $parts[] = 'Possible typo: did they mean '.$e['suggested_address'].' ('.$e['suggestion_confidence'].' confidence)?';
        }

        return implode(' ', $parts);
    }

    /** @return list<string> */
    public static function flags(array $e): array
    {
        $flags = [];
        foreach (['is_domain_typo_suspected' => 'typo_suspected', 'is_disposable' => 'disposable', 'is_role' => 'role_account',
            'is_catch_all_or_accept_all' => 'accept_all'] as $column => $flag) {
            if (true === $e[$column] || 't' === $e[$column]) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }
}
