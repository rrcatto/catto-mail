<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Message;
use App\Entity\MessageEvent;
use App\Entity\SendJob;
use App\Entity\Suppression;
use App\Entity\ValidationAddress;
use App\Entity\ValidationJob;
use App\Util\Clock;

/** Resource representations exactly as the OpenAPI component schemas define them. */
final class Presenter
{
    /** @return array<string, mixed> OpenAPI ValidationJob */
    public static function validationJob(ValidationJob $job): array
    {
        return [
            'id' => $job->getId()->toRfc4122(),
            'external_reference' => $job->getExternalReference(),
            'status' => $job->getStatus()->value,
            'submitted_at' => Clock::rfc3339($job->getSubmittedAt()),
            'started_at' => Clock::rfc3339($job->getStartedAt()),
            'completed_at' => Clock::rfc3339($job->getCompletedAt()),
            'total_addresses' => $job->getTotalAddresses(),
            'processed_count' => $job->getProcessedCount(),
            'classification_counts' => $job->getClassificationCounts(),
        ];
    }

    /** @return array<string, mixed> OpenAPI ValidationAddress */
    public static function validationAddress(ValidationAddress $a): array
    {
        return [
            'id' => $a->getId()->toRfc4122(),
            'external_address_reference' => $a->getExternalAddressReference(),
            'original_address' => $a->getOriginalAddress(),
            'normalized_address' => $a->getNormalizedAddress(),
            'syntax_status' => $a->getSyntaxStatus()?->value,
            'domain_status' => $a->getDomainStatus()?->value,
            'smtp_status' => $a->getSmtpStatus()?->value,
            'is_role' => $a->isRole(),
            'is_disposable' => $a->isDisposable(),
            'is_catch_all_or_accept_all' => $a->isCatchAllOrAcceptAll(),
            'is_domain_typo_suspected' => $a->isDomainTypoSuspected(),
            'suggested_address' => $a->getSuggestedAddress(),
            'suggestion_reason_code' => $a->getSuggestionReasonCode()?->value,
            'suggestion_confidence' => $a->getSuggestionConfidence()?->value,
            'overall_classification' => $a->getOverallClassification()?->value,
            'confidence' => $a->getConfidence()?->value,
            'diagnostic_code' => $a->getDiagnosticCode(),
            'diagnostic_text' => $a->getDiagnosticText(),
            'checked_at' => Clock::rfc3339($a->getCheckedAt()),
        ];
    }

    /** @return array<string, mixed> OpenAPI SendJob */
    public static function sendJob(SendJob $job): array
    {
        $sender = ['email' => $job->getSenderEmail()];
        if (null !== $job->getSenderName()) {
            $sender['name'] = $job->getSenderName();
        }
        $replyTo = null;
        if (null !== $job->getReplyToEmail()) {
            $replyTo = ['email' => $job->getReplyToEmail()];
            if (null !== $job->getReplyToName()) {
                $replyTo['name'] = $job->getReplyToName();
            }
        }

        return [
            'id' => $job->getId()->toRfc4122(),
            'external_reference' => $job->getExternalReference(),
            'message_class' => $job->getMessageClass()->value,
            'list_id' => $job->getListId(),
            'sender_identity' => $sender,
            'reply_to' => $replyTo,
            'tracking' => ['opens' => $job->isTrackOpens(), 'clicks' => $job->isTrackClicks()],
            'status' => $job->getStatus()->value,
            'created_at' => Clock::rfc3339($job->getCreatedAt()),
            'queued_at' => Clock::rfc3339($job->getQueuedAt()),
            'started_at' => Clock::rfc3339($job->getStartedAt()),
            'dispatch_completed_at' => Clock::rfc3339($job->getDispatchCompletedAt()),
            'completed_at' => Clock::rfc3339($job->getCompletedAt()),
            'total_recipients' => $job->getTotalRecipients(),
            'summary_counts' => $job->getSummaryCounts(),
        ];
    }

    /** @return array<string, mixed> OpenAPI Message (internal tokens and queue ids are never exposed) */
    public static function message(Message $m): array
    {
        return [
            'id' => $m->getId()->toRfc4122(),
            'send_job_id' => $m->getSendJob()->getId()->toRfc4122(),
            'external_recipient_reference' => $m->getExternalRecipientReference(),
            'recipient_address' => $m->getRecipientAddress(),
            'current_status' => $m->getCurrentStatus()->value,
            'created_at' => Clock::rfc3339($m->getCreatedAt()),
            'resolved_at' => Clock::rfc3339($m->getResolvedAt()),
        ];
    }

    /** @return array<string, mixed> OpenAPI GlobalOptOut (the reporting client's own row only; D-30) */
    public static function globalOptOut(Suppression $s): array
    {
        return [
            'id' => $s->getId()->toRfc4122(),
            'email_address' => $s->getAddressOrDomain(),
            'reason' => $s->getReason()->value,
            'status' => null === $s->getLiftedAt() ? 'active' : 'lifted',
            'external_reference' => $s->getExternalReference(),
            'created_at' => Clock::rfc3339($s->getCreatedAt()),
            'lifted_at' => Clock::rfc3339($s->getLiftedAt()),
        ];
    }

    /** @return array<string, mixed> OpenAPI MessageEvent */
    public static function messageEvent(MessageEvent $e): array
    {
        return [
            'id' => $e->getId()->toRfc4122(),
            'message_id' => $e->getMessage()->getId()->toRfc4122(),
            'event_type' => $e->getEventType()->value,
            'smtp_code' => $e->getSmtpCode(),
            'enhanced_status_code' => $e->getEnhancedStatusCode(),
            'remote_host' => $e->getRemoteHost(),
            'diagnostic' => $e->getDiagnostic(),
            'occurred_at' => Clock::rfc3339($e->getOccurredAt()),
        ];
    }

    /**
     * @param list<array<string, mixed>> $data
     *
     * @return array{data: list<array<string, mixed>>, pagination: array{limit: int, next_cursor: ?string}}
     */
    public static function page(array $data, int $limit, ?string $nextCursor): array
    {
        return ['data' => $data, 'pagination' => ['limit' => $limit, 'next_cursor' => $nextCursor]];
    }
}
