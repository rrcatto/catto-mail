<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditActor;
use App\Suppression\SuppressionAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * D-30: grant or withdraw a client's capability to report recipient global
 * opt-outs (clients.can_submit_global_suppressions, default false). Operator only;
 * audited with the operator's identity and note.
 */
#[AsCommand('smarthost:client:global-suppressions', 'Enable or disable a client\'s capability to report recipient global opt-outs (operator)')]
final class ClientGlobalSuppressionsCommand extends AdminCommand
{
    public function __construct(private readonly SuppressionAdministration $suppressions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED)
            ->addArgument('setting', InputArgument::REQUIRED, 'enable or disable')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Login email of the operator (audit actor)')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Why (kept in the audit log)');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $allowed = match ((string) $input->getArgument('setting')) {
            'enable' => true,
            'disable' => false,
            default => throw new \InvalidArgumentException('setting must be enable or disable.'),
        };
        $client = $this->client((string) $input->getArgument('client-id'));
        $changed = $this->suppressions->setGlobalSuppressionCapability($client, $allowed,
            AuditActor::user($this->operator($input->getOption('operator'))), (string) $input->getOption('note'));
        $io->writeln('can_submit_global_suppressions: '.($allowed ? 'true' : 'false').($changed ? '' : ' (unchanged)'));

        return Command::SUCCESS;
    }
}
