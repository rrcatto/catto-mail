<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditActor;
use App\Client\ClientLifecycle;
use App\Client\ClientStatusTransitions;
use App\Enum\ClientStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A client lifecycle transition (Phase 9): approve, throttle, suspend, lift a throttle,
 * reactivate, close. The operator must hold the permission of the transition
 * (PLATFORM.CLIENT.APPROVE or PLATFORM.CLIENT.RESTRICT, App\Client\ClientStatusTransitions);
 * the note is the audited reason.
 */
#[AsCommand('smarthost:client:set-status', 'Change a client status: approve, throttle, suspend, reactivate, close (operator; audited)')]
final class ClientSetStatusCommand extends AdminCommand
{
    public function __construct(private readonly ClientLifecycle $lifecycle)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED)->addArgument('status', InputArgument::REQUIRED)
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Login email of the operator (audit actor)')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Why (kept in the audit log)');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $status = ClientStatus::tryFrom((string) $input->getArgument('status')) ?? throw new \InvalidArgumentException('Unknown client status.');
        $client = $this->client((string) $input->getArgument('client-id'));
        $permission = ClientStatusTransitions::permission($client->getStatus(), $status) ?? ClientStatusTransitions::APPROVE;
        $operator = $this->operator($input->getOption('operator'), $permission);
        $this->lifecycle->changeStatus($client, $status, AuditActor::user($operator), (string) $input->getOption('note'), $operator);
        $io->writeln('status: '.$status->value);

        return Command::SUCCESS;
    }
}
