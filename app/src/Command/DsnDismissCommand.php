<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\DomainRuleViolation;
use App\Dsn\UnmatchedDsnAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Dismisses an open unmatched DSN (e.g. backscatter) with a written reason; the row is kept. */
#[AsCommand('smarthost:dsn:dismiss', 'Dismiss an unmatched DSN with a written reason (operator)')]
final class DsnDismissCommand extends AdminCommand
{
    public function __construct(private readonly UnmatchedDsnAdministration $dsns)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED)
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Login email of the operator (audit actor)')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why no message applies (required)');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $id = (string) $input->getArgument('id');
        $d = $this->dsns->find($id) ?? throw new DomainRuleViolation("No unmatched DSN $id.");
        $operator = $this->operator($input->getOption('operator'));
        $this->dsns->dismiss($d, (string) $input->getOption('reason'), $operator);
        $io->writeln('status: '.$d->getStatus()->value);

        return Command::SUCCESS;
    }
}
