<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\DomainRuleViolation;
use App\Enum\SuppressionScopeType;
use App\Suppression\SuppressionAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lifts one suppression of any reason (sets lifted_at; the row and its history are
 * kept). Other suppressions of the same address stay in force - check with
 * smarthost:suppression:list --address=... --active.
 */
#[AsCommand('smarthost:suppression:lift', 'Lift one suppression (operator; audited)')]
final class SuppressionLiftCommand extends AdminCommand
{
    public function __construct(private readonly SuppressionAdministration $suppressions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('suppression-id', InputArgument::REQUIRED)
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Login email of the operator (audit actor)')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Why (kept in the audit log)');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $id = (string) $input->getArgument('suppression-id');
        $s = $this->suppressions->find($id) ?? throw new DomainRuleViolation("No suppression $id.");
        $lifted = $this->suppressions->lift($s, $this->operator($input->getOption('operator'), 'PLATFORM.SUPPRESSION.MANAGE'), (string) $input->getOption('note'));
        $io->writeln('lifted: '.($lifted ? 'true' : 'false (already lifted)'));
        if (SuppressionScopeType::Address === $s->getScopeType()) {
            $remaining = $this->suppressions->search($s->getAddressOrDomain(), null, true, 1000);
            $io->writeln('other_active_suppressions_of_address: '.\count($remaining));
        }

        return Command::SUCCESS;
    }
}
