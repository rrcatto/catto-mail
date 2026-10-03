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

#[AsCommand('smarthost:user:set-status', 'Enable or disable a dashboard user')]
final class UserSetStatusCommand extends AdminCommand
{
    public function __construct(private readonly AccountAdministration $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)->addArgument('status', InputArgument::REQUIRED, 'active or disabled');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $status = (string) $input->getArgument('status');
        if (!\in_array($status, ['active', 'disabled'], true)) {
            throw new \InvalidArgumentException('status must be active or disabled.');
        }
        $this->accounts->setDisabled($this->user((string) $input->getArgument('email')), 'disabled' === $status, $this->actor());
        $io->writeln('status: '.$status);

        return Command::SUCCESS;
    }
}
