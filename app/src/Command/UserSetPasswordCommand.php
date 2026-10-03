<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\AccountAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('smarthost:user:set-password', 'Set a dashboard user password (read from STDIN)')]
final class UserSetPasswordCommand extends AdminCommand
{
    public function __construct(private readonly AccountAdministration $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED);
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $this->accounts->setPassword($this->user((string) $input->getArgument('email')), $this->secretFromStdin($input), $this->actor());
        $io->writeln('password updated');

        return Command::SUCCESS;
    }
}
