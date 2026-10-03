<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * `validation_evidence_type` from docs/contracts/status-vocabulary.yaml (generated; kept in sync by
 * tests/Contract/VocabularyTest.php, which also compares the database CHECK constraints).
 */
enum ValidationEvidenceType: string
{
    case DnsMx = 'dns_mx';
    case DnsAddress = 'dns_address';
    case SmtpConnect = 'smtp_connect';
    case SmtpEhlo = 'smtp_ehlo';
    case SmtpMailFrom = 'smtp_mail_from';
    case SmtpRcptTo = 'smtp_rcpt_to';
    case SmtpAcceptAllProbe = 'smtp_accept_all_probe';
}
