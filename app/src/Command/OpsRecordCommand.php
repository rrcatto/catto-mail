<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Audits an installation-wide delivery control performed on the host (Phase 8):
 * `smarthostctl prod live-enable|live-disable|pause|resume` call it before acting,
 * so the action is attributed to an operator holding SYSTEM.DELIVERY.CONTROL (ADMIN)
 * with a written note, and refuse to act when it fails.
 */
#[AsCommand('smarthost:ops:record', 'Audit an installation-wide delivery control (live activation, emergency pause) by an operator')]
final class OpsRecordCommand extends AdminCommand
{
    public const ACTIONS = ['delivery.live_enabled', 'delivery.live_disabled', 'delivery.outbound_paused', 'delivery.outbound_resumed'];

    public function __construct(private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, implode(', ', self::ACTIONS))
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Login email of an operator with SYSTEM.DELIVERY.CONTROL')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Why (required)');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $action = (string) $input->getArgument('action');
        if (!\in_array($action, self::ACTIONS, true)) {
            throw new DomainRuleViolation('Unknown action; expected one of: '.implode(', ', self::ACTIONS).'.');
        }
        $note = trim((string) $input->getOption('note'));
        if ('' === $note) {
            throw new DomainRuleViolation('--note is required: record why.');
        }
        $operator = $this->operator($input->getOption('operator'), 'SYSTEM.DELIVERY.CONTROL');
        $id = $this->audit->record(AuditActor::user($operator), $action, 'installation', null, ['note' => mb_substr($note, 0, 1000)]);
        $io->writeln("audit_log: $action by {$operator->getEmail()} ({$id->toRfc4122()})");

        return Command::SUCCESS;
    }
}
