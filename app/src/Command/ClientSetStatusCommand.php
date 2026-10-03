<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\AccountAdministration;
use App\Enum\ClientStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('smarthost:client:set-status', 'Change a client status (approval, throttling, suspension, closure)')]
final class ClientSetStatusCommand extends AdminCommand
{
    public function __construct(private readonly AccountAdministration $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED)->addArgument('status', InputArgument::REQUIRED);
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $status = ClientStatus::tryFrom((string) $input->getArgument('status')) ?? throw new \InvalidArgumentException('Unknown client status.');
        $this->accounts->setClientStatus($this->client((string) $input->getArgument('client-id')), $status, $this->actor());
        $io->writeln('status: '.$status->value);

        return Command::SUCCESS;
    }
}
