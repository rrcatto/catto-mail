<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Effective API limits. Configuration may lower but never raise the contract
 * ceilings (spec sending.ingestion, openapi RecipientBatchRequest).
 */
final class Limits
{
    public const MAX_RECIPIENTS_PER_JOB = 10000;
    public const MAX_RECIPIENTS_PER_BATCH = 500;
    public const MAX_VALIDATION_ADDRESSES = 10000;
    public const MAX_REQUEST_BYTES = 10 * 1024 * 1024;

    public readonly int $maxRecipientsPerJob;
    public readonly int $maxRecipientsPerBatch;
    public readonly int $maxRequestBytes;

    public function __construct(int $configuredMaxRecipients, int $configuredMaxRecipientsPerBatch, int $maxRequestBytes)
    {
        foreach (['APP_SEND_JOB_MAX_RECIPIENTS' => $configuredMaxRecipients,
                  'APP_SEND_JOB_MAX_RECIPIENTS_PER_BATCH' => $configuredMaxRecipientsPerBatch,
                  'APP_API_MAX_REQUEST_BYTES' => $maxRequestBytes] as $name => $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException("$name must be a positive integer.");
            }
        }
        $this->maxRecipientsPerJob = min($configuredMaxRecipients, self::MAX_RECIPIENTS_PER_JOB);
        $this->maxRecipientsPerBatch = min($configuredMaxRecipientsPerBatch, self::MAX_RECIPIENTS_PER_BATCH);
        $this->maxRequestBytes = min($maxRequestBytes, self::MAX_REQUEST_BYTES);
    }
}
