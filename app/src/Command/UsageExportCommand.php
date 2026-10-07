<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditActor;
use App\Usage\UsageExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The deterministic usage export for a billing integration (Phase 9; PLATFORM.USAGE.EXPORT, audited). */
#[AsCommand('smarthost:usage:export', 'Export one client\'s usage of a period as JSON or CSV (operator; audited)')]
final class UsageExportCommand extends AdminCommand
{
    public function __construct(private readonly UsageExporter $exporter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED)
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'json or csv', 'json')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Login email of the operator (audit actor)');
        $this->addPeriodOptions();
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $operator = $this->operator($input->getOption('operator'), 'PLATFORM.USAGE.EXPORT');
        $export = $this->exporter->export($this->client((string) $input->getArgument('client-id')), $this->usagePeriod($input, 'previous_month'),
            AuditActor::user($operator));
        $io->write('csv' === $input->getOption('format') ? UsageExporter::csv($export)
            : json_encode($export, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

        return Command::SUCCESS;
    }
}
