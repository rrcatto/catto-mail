<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\ApiKeyManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('smarthost:api-key:create', 'Create an API key for a client and print the raw key once')]
final class ApiKeyCreateCommand extends AdminCommand
{
    public function __construct(private readonly ApiKeyManager $keys)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED)
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        [$key, $raw] = $this->keys->create($this->client((string) $input->getArgument('client-id')), $input->getOption('name'), $this->actor());
        $io->writeln('api_key_id: '.$key->getId()->toRfc4122());
        $io->writeln('api_key: '.$raw);
        $io->note('The raw key is shown once and cannot be recovered; only its SHA-256 hash is stored.');

        return Command::SUCCESS;
    }
}
