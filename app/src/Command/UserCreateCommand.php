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

#[AsCommand('smarthost:user:create', 'Create a dashboard user (password from STDIN with --password-stdin)')]
final class UserCreateCommand extends AdminCommand
{
    public function __construct(private readonly AccountAdministration $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Login email (unique, case-insensitive)')
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED)
            ->addOption('operator', null, InputOption::VALUE_NONE, 'Grant the global operator role')
            ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the password from the first line of STDIN');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $password = $input->getOption('password-stdin') ? $this->secretFromStdin($input) : null;
        $user = $this->accounts->createUser((string) $input->getArgument('email'), $input->getOption('display-name'), $password,
            (bool) $input->getOption('operator'), $this->actor());
        $io->writeln('user_id: '.$user->getId()->toRfc4122());

        return Command::SUCCESS;
    }
}
