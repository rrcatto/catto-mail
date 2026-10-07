<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\System\DeliveryControl;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The emergency stop from the console (specification 2.11), used by `smarthostctl prod
 * pause` and `resume`, which also hold or release the Postfix queue. The delivery daemon
 * stops submitting within seconds of `on`. Audited with the operator (SYSTEM.DELIVERY.CONTROL)
 * before anything changes.
 */
#[AsCommand('smarthost:delivery:emergency-stop', 'Set or lift the installation-wide emergency stop (audited)')]
final class DeliveryEmergencyStopCommand extends AdminCommand
{
    public function __construct(private readonly DeliveryControl $control, private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('state', InputArgument::REQUIRED, 'on | off | status')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Login email of an operator with SYSTEM.DELIVERY.CONTROL')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Why (kept in the audit log)');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $state = (string) $input->getArgument('state');
        if ('status' === $state) {
            $s = $this->control->status();
            $io->writeln('mode: '.$s['mode']);
            $io->writeln('emergency_stop: '.($s['emergency_stop'] ? 'on' : 'off'));
            $io->writeln($s['explanation']);

            return Command::SUCCESS;
        }
        if (!\in_array($state, ['on', 'off'], true)) {
            throw new \InvalidArgumentException('state must be on, off or status');
        }
        $operator = $this->operator($input->getOption('operator'), 'SYSTEM.DELIVERY.CONTROL');
        $note = trim((string) $input->getOption('note'));
        if (mb_strlen($note) < 3 || mb_strlen($note) > 1000) {
            throw new \InvalidArgumentException('--note <why> is required (3 to 1000 characters).');
        }
        $this->audit->record(AuditActor::user($operator), 'on' === $state ? 'delivery.outbound_paused' : 'delivery.outbound_resumed',
            'system', null, ['note' => $note, 'via' => 'console']);
        $this->control->setFromConsole('on' === $state, $operator, $note);
        $io->writeln('emergency_stop: '.$state);

        return Command::SUCCESS;
    }
}
