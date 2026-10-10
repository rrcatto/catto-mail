<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Grants or revokes an installation-wide role (audited as "system"). The dashboard does the same under Access › Users. */
#[AsCommand('smarthost:user:role', 'Grant or revoke an installation-wide role (e.g. OPERATOR, ADMIN)')]
final class UserRoleCommand extends AdminCommand
{
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('role', InputArgument::REQUIRED, 'Role key, e.g. OPERATOR')
            ->addArgument('action', InputArgument::REQUIRED, 'grant or revoke');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $action = (string) $input->getArgument('action');
        if (!\in_array($action, ['grant', 'revoke'], true)) {
            throw new \InvalidArgumentException('action must be grant or revoke.');
        }
        $user = $this->user((string) $input->getArgument('email'));
        $key = strtoupper((string) $input->getArgument('role'));
        $changed = 'grant' === $action
            ? $this->accessControl->grantRoleAsSystem($user, $key, $this->actor())
            : $this->accessControl->revokeRoleAsSystem($user, $key, $this->actor());
        $io->writeln(\sprintf('%s %s: %s', $action, $key, $changed ? 'done' : 'nothing to change'));

        return Command::SUCCESS;
    }
}
