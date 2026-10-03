<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\SendingDomainService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('smarthost:domain:add', 'Register a sending domain and print its TXT verification record')]
final class DomainAddCommand extends AdminCommand
{
    public function __construct(private readonly SendingDomainService $domains)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED)->addArgument('domain', InputArgument::REQUIRED);
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $domain = $this->domains->register($this->client((string) $input->getArgument('client-id')), (string) $input->getArgument('domain'), $this->actor());
        $io->writeln('sending_domain_id: '.$domain->getId()->toRfc4122());
        $io->writeln('txt_name: '.SendingDomainService::txtRecordName($domain));
        $io->writeln('txt_value: '.SendingDomainService::txtRecordValue($domain));

        return Command::SUCCESS;
    }
}
