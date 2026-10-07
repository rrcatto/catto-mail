<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ApiKey;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Phase 9: a client's API keys (prefix, name, dates, state); never a secret, which is not stored. */
#[AsCommand('smarthost:api-key:list', 'List a client\'s API keys')]
final class ApiKeyListCommand extends AdminCommand
{
    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED);
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $client = $this->client((string) $input->getArgument('client-id'));
        $fmt = static fn (?\DateTimeImmutable $t): string => $t?->format('Y-m-d H:i') ?? '-';
        $rows = [];
        foreach ($this->em->getRepository(ApiKey::class)->findBy(['client' => $client], ['createdAt' => 'ASC']) as $key) {
            $state = $key->isRevoked() ? 'revoked' : ($key->isExpired() ? 'expired' : 'usable');
            $rows[] = [$key->getId()->toRfc4122(), $key->getKeyPrefix(), $key->getName() ?? '-', $fmt($key->getCreatedAt()),
                $fmt($key->getLastUsedAt()), $fmt($key->getExpiresAt()), $state];
        }
        $io->table(['id', 'prefix', 'name', 'created', 'last used', 'expires', 'state'], $rows);

        return Command::SUCCESS;
    }
}
