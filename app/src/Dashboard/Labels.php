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
        'client_origin' => ['operator' => 'Created by an operator', 'public_application' => 'Public sign-up'],
        'quota_metric' => ['validation_jobs' => 'Validation jobs', 'validation_addresses' => 'Validation addresses submitted',
            'send_jobs' => 'Send jobs', 'send_recipients' => 'Send recipients accepted'],
        'quota_period' => ['day' => 'Today', 'month' => 'This month'],
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

    /** What an audit entry did, in plain words (System › Audit log); the stored action stays in its tooltip. */
    private const AUDIT_ACTIONS = [
        'auth.login' => 'Signed in', 'auth.login_link_requested' => 'Sign-in email sent',
        'auth.sign_up_link_requested' => 'Sign-up email sent', 'auth.host_login_link_issued' => 'Sign-in link made on the server',
        'acl.role_created' => 'Role created', 'acl.role_updated' => 'Role changed', 'acl.role_deleted' => 'Role deleted',
        'acl.role_permissions_updated' => 'Role permissions changed',
        'user.created' => 'User created', 'user.enabled' => 'User enabled', 'user.disabled' => 'User disabled',
        'user.display_name_changed' => 'Display name changed', 'user.role_granted' => 'Role granted', 'user.role_revoked' => 'Role revoked',
        'client.created' => 'Client created', 'client.approved' => 'Client approved', 'client.throttled' => 'Client throttled',
        'client.unthrottled' => 'Client throttle lifted', 'client.suspended' => 'Client suspended', 'client.reactivated' => 'Client reactivated',
        'client.closed' => 'Client closed', 'client.account_updated' => 'Client account changed', 'client.limits_changed' => 'Client limits changed',
        'client.note_added' => 'Note added', 'client.policy_accepted' => 'Acceptable use policy accepted',
        'client.policy_requirement_changed' => 'Policy requirement changed',
        'client.global_suppressions_enabled' => 'Global opt-out reporting allowed', 'client.global_suppressions_disabled' => 'Global opt-out reporting withdrawn',
        'client_alert.acknowledged' => 'Alert acknowledged', 'client_membership.created' => 'Member added',
        'client_membership.removed' => 'Member removed', 'client_membership.role_changed' => 'Member role changed',
        'api_key.created' => 'API key created', 'api_key.revoked' => 'API key revoked',
        'webhook_endpoint.created' => 'Webhook endpoint added', 'webhook_endpoint.updated' => 'Webhook endpoint changed',
        'webhook_endpoint.removed' => 'Webhook endpoint removed', 'webhook_endpoint.secret_rotated' => 'Webhook secret rotated',
        'webhook.test' => 'Test webhook sent', 'webhook.test_requested' => 'Test webhook requested',
        'application_connection.approved' => 'Application connection approved', 'application_connection.connected' => 'Application connected',
        'application_connection.revoked' => 'Application disconnected',
        'sending_domain.created' => 'Sending domain added', 'sending_domain.verified' => 'Sending domain verified',
        'sending_domain.verification_failed' => 'Sending domain verification failed', 'sending_domain.disabled' => 'Sending domain disabled',
        'sending_domain.enabled' => 'Sending domain enabled', 'sending_domain.dkim_changed' => 'Sending domain DKIM changed',
        'sending_domain.dkim_check_failed' => 'Sending domain DKIM check failed',
        'billing_statement.prepared' => 'Statement prepared', 'billing_statement.finalized' => 'Statement finalised',
        'billing_statement.exported' => 'Statement exported', 'billing_statement.voided' => 'Statement voided', 'usage.exported' => 'Usage exported',
        'address_batch.imported' => 'Address batch uploaded', 'address_batch.validation_started' => 'Address batch validation started',
        'address_batch.review_decided' => 'Address batch review decided', 'address_batch.typo_decided' => 'Typo correction decided',
        'address_batch.compliance_approved' => 'Address batch approved for sending', 'address_batch.list_id_set' => 'Address batch list ID set',
        'address_batch.send_created' => 'Send created from an address batch', 'repermission.responded' => 'Permission request answered',
        'suppression.operator_created' => 'Suppression added', 'suppression.operator_lifted' => 'Suppression lifted',
        'suppression.global_opt_out_created' => 'Global opt-out recorded', 'suppression.global_opt_out_lifted' => 'Global opt-out lifted',
        'suppression.global_opt_out_reaffirmed' => 'Global opt-out confirmed again',
        'unmatched_dsn.dismissed' => 'Unmatched bounce dismissed', 'unmatched_dsn.match_requested' => 'Unmatched bounce matched to a message',
        'delivery.live_enabled' => 'Live delivery switched on', 'delivery.live_disabled' => 'Live delivery switched off (HELD)',
        'delivery.outbound_paused' => 'Sending stopped (emergency stop)', 'delivery.outbound_resumed' => 'Sending resumed',
        'setup.step_done' => 'Setup step marked done', 'setup.step_skipped' => 'Setup step skipped', 'setup.step_reopened' => 'Setup step reopened',
        'settings.apply' => 'Settings applied', 'system.test_confirmed' => 'Test result confirmed', 'system.request.cancelled' => 'Host request cancelled',
        'dkim.generate' => 'DKIM key generated', 'dkim.activate' => 'DKIM key activated',
    ];

    /** What the host agent was asked to do (system.request.<action>). */
    private const HOST_REQUESTS = [
        'diagnostics.run' => 'run every check', 'delivery.pause' => 'stop sending', 'delivery.resume' => 'resume sending',
        'delivery.live_enable' => 'switch live delivery on', 'delivery.live_disable' => 'return to HELD', 'backup.run' => 'take a backup',
        'backup.restore_rehearsal' => 'rehearse a restore', 'dkim.generate' => 'generate a DKIM key', 'dkim.activate' => 'activate a DKIM key',
        'tls.renew' => 'renew the certificates', 'settings.apply' => 'apply the settings',
    ];

    #[AsTwigFilter('audit_label')]
    public static function auditAction(?string $action): string
    {
        $action = (string) $action;
        if (isset(self::AUDIT_ACTIONS[$action])) {
            return self::AUDIT_ACTIONS[$action];
        }
        if (str_starts_with($action, 'system.request.')) {
            $what = substr($action, \strlen('system.request.'));

            return 'Host asked to '.(self::HOST_REQUESTS[$what] ?? $what);
        }

        return '' === $action ? '—' : $action;
    }

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
