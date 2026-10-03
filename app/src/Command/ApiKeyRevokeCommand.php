<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ApiKey;
use App\Security\ApiKeyManager;
use App\Domain\DomainRuleViolation;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('smarthost:api-key:revoke', 'Revoke an API key')]
final class ApiKeyRevokeCommand extends AdminCommand
{
    public function __construct(private readonly ApiKeyManager $keys)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('api-key-id', InputArgument::REQUIRED);
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $id = (string) $input->getArgument('api-key-id');
        $key = Uuid::isValid($id) ? $this->em->find(ApiKey::class, Uuid::fromString($id)) : null;
        if (null === $key) {
            throw new DomainRuleViolation("No API key $id.");
        }
        $this->keys->revoke($key, $this->actor());
        $io->writeln('revoked_at: '.$key->getRevokedAt()?->format(\DATE_RFC3339_EXTENDED));

        return Command::SUCCESS;
    }
}
