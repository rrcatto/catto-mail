<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `typo_reason_code` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum TypoReasonCode: string
{
    case ProviderDomainEditDistance = 'provider_domain_edit_distance';
    case Transposition = 'transposition';
    case TldTypo = 'tld_typo';
    case LegacyDomainAlias = 'legacy_domain_alias';
    case OtherDomainTypoRule = 'other_domain_typo_rule';
}
