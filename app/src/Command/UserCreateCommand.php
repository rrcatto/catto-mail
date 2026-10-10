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

/**
 * Creates a dashboard user (passwordless: they sign in with an emailed link).
 * Usually done in the dashboard (Access › Users); this is the scripted path.
 */
#[AsCommand('smarthost:user:create', 'Create a dashboard user (signs in with an emailed link)')]
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
            ->addOption('role', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Grant this role (e.g. OPERATOR); repeatable');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $user = $this->accounts->createUser((string) $input->getArgument('email'), $input->getOption('display-name'), $this->actor());
        foreach ((array) $input->getOption('role') as $key) {
            $this->accessControl->grantRoleAsSystem($user, (string) $key, $this->actor());
        }
        $io->writeln('user_id: '.$user->getId()->toRfc4122());

        return Command::SUCCESS;
    }
}
