<?php

declare(strict_types=1);

namespace App\Sending;

use App\Entity\SendingDomain;
use App\Enum\DkimStatus;

/**
 * The submit-time sending-domain rule (spec sending_domains.live_sending_rule): verified, and
 * in production also DKIM active - unless the explicitly controlled local/test mode
 * SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS is on (never in production;
 * App\Config\SafetyGuard). A disabled domain is never usable.
 *
 * One rule for the submit of a send job (App\Sending\SendJobService) and for what the API tells
 * an application about its domains (GET /v1/sending-domains, `can_send`), so the two cannot
 * disagree.
 */
final class SendingDomainRule
{
    public function __construct(
        private readonly string $smarthostEnv,
        private readonly bool $allowUnverifiedSendingDomains,
    ) {
    }

    /** @return list<string> why a job from this domain cannot be submitted now (empty: it can) */
    public function problems(SendingDomain $domain): array
    {
        if ($domain->isDisabled()) {
            return ['The sending domain is disabled.'];
        }
        if ($this->allowUnverifiedSendingDomains && 'production' !== $this->smarthostEnv) {
            return [];
        }
        $problems = [];
        if (!$domain->isVerified()) {
            $problems[] = 'The sending domain has not passed DNS TXT verification.';
        }
        if ('production' === $this->smarthostEnv && DkimStatus::Active !== $domain->getDkimStatus()) {
            $problems[] = 'The sending domain has no active DKIM configuration.';
        }

        return $problems;
    }

    public function canSend(SendingDomain $domain): bool
    {
        return [] === $this->problems($domain);
    }
}
