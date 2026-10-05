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

/**
 * Requests the resolution of an open unmatched DSN to a message (open ->
 * match_requested). The Go delivery daemon then interprets the DSN and creates the
 * transport event; this command never does.
 */
#[AsCommand('smarthost:dsn:match', 'Request matching an unmatched DSN to a message; Go applies it (operator)')]
final class DsnMatchCommand extends AdminCommand
{
    public function __construct(private readonly UnmatchedDsnAdministration $dsns)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Unmatched DSN id')
            ->addArgument('message-id', InputArgument::REQUIRED, 'The Smarthost message the DSN belongs to')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Login email of the operator (recorded as resolution_requested_by)')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Optional resolution note');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $id = (string) $input->getArgument('id');
        $d = $this->dsns->find($id) ?? throw new DomainRuleViolation("No unmatched DSN $id.");
        $this->dsns->requestMatch($d, (string) $input->getArgument('message-id'), $this->operator($input->getOption('operator')), $input->getOption('note'));
        $io->writeln('status: '.$d->getStatus()->value);
        $io->note('The delivery daemon applies the resolution; check with smarthost:dsn:show.');

        return Command::SUCCESS;
    }
}
