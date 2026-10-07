<?php

declare(strict_types=1);

namespace App\Dashboard;

use Twig\Attribute\AsTwigFilter;

/**
 * Human wording for stored vocabulary values (docs/contracts/status-vocabulary.yaml).
 * One place for the terminology rules of the specification
 * (message_tracking.terminology, user_interfaces.UI_requirements):
 *   - remote_accepted is "Remote accepted": the receiving server took
 *     responsibility; never "delivered" or "inbox";
 *   - outcome_unknown is never presented as a success;
 *   - an open is a "Recorded open", never a read.
 * Unknown values are shown verbatim (escaped by Twig).
 */
final class Labels
{
    private const LABELS = [
        'message_status' => [
            'created' => 'Created', 'queued' => 'Queued', 'submitted' => 'Submitted to Postfix', 'deferred' => 'Deferred',
            'outcome_unknown' => 'Outcome unknown', 'remote_accepted' => 'Remote accepted', 'soft_bounced' => 'Soft bounced',
            'hard_bounced' => 'Hard bounced', 'complained' => 'Complaint', 'failed' => 'Failed', 'suppressed' => 'Suppressed',
        ],
        'event_type' => [
            'message_created' => 'Message created', 'message_queued' => 'Queued for submission',
            'message_suppressed' => 'Not submitted: suppressed', 'submission_failed' => 'Submission failed',
            'submitted_to_postfix' => 'Submitted to Postfix', 'postfix_queued' => 'Postfix queued',
            'delivery_attempt' => 'Delivery attempt', 'deferred' => 'Deferred (temporary failure)',
            'connection_failure' => 'Connection failure', 'remote_accepted' => 'Remote accepted',
            'soft_bounce' => 'Soft bounce', 'hard_bounce' => 'Hard bounce', 'complaint' => 'Complaint',
            'transport_outcome_unknown' => 'Outcome unknown (reconciliation)', 'open_recorded' => 'Recorded open',
            'click_recorded' => 'Recorded click', 'dsn_unmatched' => 'DSN could not be reconciled',
        ],
        'event_source' => [
            'delivery_daemon' => 'Delivery daemon', 'postfix_submission' => 'Postfix submission', 'postfix_log' => 'Postfix log',
            'dsn_spool' => 'Inbound DSN', 'unmatched_dsn_resolution' => 'Operator-resolved DSN',
            'queue_reconciliation' => 'Queue reconciliation', 'tracking_endpoint' => 'Tracking endpoint',
        ],
        'suppression_reason' => [
            'hard_bounce' => 'Hard bounce', 'complaint' => 'Complaint', 'repeated_soft_bounce' => 'Repeated soft bounces',
            'operator_block' => 'Operator block', 'client_abuse_block' => 'Client abuse block',
            'recipient_global_opt_out' => 'Recipient global opt-out',
        ],
        // Broad reasons shown to a client for a global suppression it did not report (no provenance).
        'suppression_reason_client' => [
            'hard_bounce' => 'Address permanently rejected mail', 'complaint' => 'Recipient complaint',
            'repeated_soft_bounce' => 'Repeated temporary failures', 'operator_block' => 'Blocked by the operator',
            'client_abuse_block' => 'Blocked by the operator', 'recipient_global_opt_out' => 'Recipient asked not to be contacted',
        ],
        'validation_job_status' => [
            'queued' => 'Queued', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed', 'cancelled' => 'Cancelled',
        ],
        'send_job_status' => [
            'collecting' => 'Collecting recipients', 'queued' => 'Queued', 'processing' => 'Processing',
            'dispatched' => 'Dispatched to Postfix', 'completed' => 'Completed', 'failed' => 'Failed', 'cancelled' => 'Cancelled',
        ],
        'classification' => [
            'deliverable' => 'Deliverable', 'probably_deliverable' => 'Probably deliverable', 'undeliverable' => 'Undeliverable',
            'temporarily_unverifiable' => 'Temporarily unverifiable', 'unknown' => 'Unknown', 'risky' => 'Risky',
        ],
        'client_status' => [
            'pending_approval' => 'Pending approval', 'active' => 'Active', 'throttled' => 'Throttled', 'suspended' => 'Suspended', 'closed' => 'Closed',
        ],
        'domain_status' => ['pending' => 'Pending verification', 'verified' => 'Verified', 'disabled' => 'Disabled'],
        'dkim_status' => ['not_configured' => 'Not configured', 'pending_dns' => 'Pending DNS', 'active' => 'Active', 'disabled' => 'Disabled'],
        'dsn_status' => ['open' => 'Open', 'match_requested' => 'Match requested (awaiting delivery daemon)', 'matched' => 'Matched', 'dismissed' => 'Dismissed'],
        'usage_type' => ['validation_address' => 'Validation addresses', 'message_submitted' => 'Messages submitted'],
        // Phase 9
        'client_origin' => ['operator' => 'Created by an operator', 'public_application' => 'Public application'],
        'quota_metric' => ['validation_jobs' => 'Validation jobs', 'validation_addresses' => 'Validation addresses submitted',
            'send_jobs' => 'Send jobs', 'send_recipients' => 'Send recipients accepted'],
        'quota_period' => ['day' => 'Today (UTC)', 'month' => 'This month (UTC)'],
        'alert_metric' => ['hard_bounce_rate' => 'Hard-bounce rate', 'complaint_rate' => 'Complaint rate',
            'deferral_rate' => 'Deferral / soft-bounce rate', 'volume_increase' => 'Volume increase'],
        'alert_severity' => ['warning' => 'Warning', 'critical' => 'Critical'],
        'statement_status' => ['draft' => 'Draft', 'finalized' => 'Finalized', 'exported' => 'Exported', 'void' => 'Void'],
        'reconciliation_status' => ['consistent' => 'Consistent', 'inconsistent' => 'Inconsistent'],
        'policy_source' => ['client_dashboard' => 'Accepted in the dashboard', 'operator_recorded' => 'Recorded by an operator'],
        'limit' => [
            'api_requests_per_minute' => 'API requests per minute (all keys)', 'validation_jobs_per_day' => 'Validation jobs per day',
            'validation_addresses_per_day' => 'Validation addresses per day', 'validation_addresses_per_month' => 'Validation addresses per month',
            'send_jobs_per_day' => 'Send jobs per day', 'send_recipients_per_day' => 'Send recipients per day',
            'send_recipients_per_month' => 'Send recipients per month', 'max_recipients_per_send_job' => 'Recipients per send job',
            'max_api_keys' => 'Usable API keys', 'max_webhook_endpoints' => 'Webhook endpoints', 'max_sending_domains' => 'Sending domains',
        ],
        // What each client status means for the client (shown on the client's own overview).
        'client_status_meaning' => [
            'pending_approval' => 'Your account awaits approval. You can sign in and read, but you cannot create validation or send jobs yet.',
            'active' => 'Your account is approved. Work is accepted within the limits shown below.',
            'throttled' => 'Your account is throttled by the operator: sending is paced more slowly and the API accepts fewer requests per minute. You can still create work.',
            'suspended' => 'Your account is suspended by the operator: new validation and send work is refused and work in progress is paused. You can still read your data. Contact the operator.',
            'closed' => 'Your account is closed.',
        ],
    ];

    #[AsTwigFilter('label')]
    public static function label(mixed $value, string $vocabulary): string
    {
        if (null === $value || '' === $value) {
            return '—';
        }

        return self::LABELS[$vocabulary][(string) $value] ?? (string) $value;
    }

    /** @return array<string, string> value => label */
    public static function options(string $vocabulary): array
    {
        return self::LABELS[$vocabulary] ?? [];
    }
}
