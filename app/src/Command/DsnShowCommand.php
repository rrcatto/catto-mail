<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\DomainRuleViolation;
use App\Dsn\UnmatchedDsnAdministration;
use App\Util\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows one unmatched DSN: the parsed evidence and, with --raw, the retained raw
 * message. The DSN is untrusted remote content: it is printed as plain text, never
 * interpreted.
 */
#[AsCommand('smarthost:dsn:show', 'Show one unmatched DSN with its parsed evidence (operator)')]
final class DsnShowCommand extends AdminCommand
{
    public function __construct(private readonly UnmatchedDsnAdministration $dsns)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED)->addOption('raw', null, InputOption::VALUE_NONE, 'Also print the retained raw message');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $id = (string) $input->getArgument('id');
        $d = $this->dsns->find($id) ?? throw new DomainRuleViolation("No unmatched DSN $id.");
        $fields = [
            'id' => $d->getId()->toRfc4122(), 'status' => $d->getStatus()->value, 'classification' => $d->getClassification()->value,
            'received_at' => Clock::rfc3339($d->getReceivedAt()), 'spool_ingest_key' => $d->getSpoolIngestKey(),
            'content_sha256' => $d->getContentSha256(), 'verp_token' => $d->getVerpToken(), 'original_recipient' => $d->getOriginalRecipient(),
            'final_recipient' => $d->getFinalRecipient(), 'postfix_queue_id' => $d->getPostfixQueueId(), 'reporting_mta' => $d->getReportingMta(),
            'enhanced_status_code' => $d->getEnhancedStatusCode(), 'matched_message_id' => $d->getMatchedMessage()?->getId()->toRfc4122(),
            'resolution_requested_by' => $d->getResolutionRequestedBy()?->getEmail(), 'resolution_requested_at' => Clock::rfc3339($d->getResolutionRequestedAt()),
            'resolution_event_id' => $d->getResolutionEvent()?->getId()->toRfc4122(), 'resolution_note' => $d->getResolutionNote(),
            'resolved_at' => Clock::rfc3339($d->getResolvedAt()),
        ];
        foreach ($fields as $k => $v) {
            $io->writeln($k.': '.self::plain($v ?? '-'));
        }
        $io->writeln('detail_json: '.self::plain(json_encode($d->getDetail(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}'));
        if ($input->getOption('raw')) {
            $io->writeln('raw_message:');
            $io->writeln(self::plain($d->getRawMessage()), \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }

    /** Remote content must not drive the terminal: drop control characters except newline and tab. */
    private static function plain(string $s): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $s);
    }
}
