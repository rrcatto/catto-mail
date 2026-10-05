<?php

declare(strict_types=1);

namespace App\Command;

use App\Dsn\UnmatchedDsnAdministration;
use App\Entity\UnmatchedDsn;
use App\Enum\UnmatchedDsnStatus;
use App\Util\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The unmatched-DSN operator queue (D-05), oldest first. */
#[AsCommand('smarthost:dsn:list', 'List unmatched DSNs (operator queue)')]
final class DsnListCommand extends AdminCommand
{
    public function __construct(private readonly UnmatchedDsnAdministration $dsns)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('status', null, InputOption::VALUE_REQUIRED, 'open, match_requested, matched, dismissed or all', 'open')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows', '50');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $status = 'all' === $input->getOption('status') ? null
            : (UnmatchedDsnStatus::tryFrom((string) $input->getOption('status')) ?? throw new \InvalidArgumentException('Unknown status.'));
        $rows = $this->dsns->list($status, (int) $input->getOption('limit'));
        $io->table(['id', 'received_at', 'status', 'classification', 'final_recipient', 'enhanced', 'reporting_mta'],
            array_map(static fn (UnmatchedDsn $d): array => [
                $d->getId()->toRfc4122(), Clock::rfc3339($d->getReceivedAt()), $d->getStatus()->value, $d->getClassification()->value,
                $d->getFinalRecipient() ?? $d->getOriginalRecipient() ?? '-', $d->getEnhancedStatusCode() ?? '-', $d->getReportingMta() ?? '-',
            ], $rows));
        $io->writeln('rows: '.\count($rows));

        return Command::SUCCESS;
    }
}
