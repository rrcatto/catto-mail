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

#[AsCommand('smarthost:client:create', 'Create a client (tenant)')]
final class ClientCreateCommand extends AdminCommand
{
    public function __construct(private readonly AccountAdministration $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('company', null, InputOption::VALUE_REQUIRED, 'Company name')
            ->addOption('contact-email', null, InputOption::VALUE_REQUIRED, 'Contact email')
            ->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Plan name', 'standard')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Initial client status', 'pending_approval');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $status = ClientStatus::tryFrom((string) $input->getOption('status')) ?? throw new \InvalidArgumentException('Unknown client status.');
        $client = $this->accounts->createClient((string) $input->getOption('company'), (string) $input->getOption('contact-email'),
            (string) $input->getOption('plan'), $status, $this->actor());
        $io->writeln('client_id: '.$client->getId()->toRfc4122());

        return Command::SUCCESS;
    }
}
