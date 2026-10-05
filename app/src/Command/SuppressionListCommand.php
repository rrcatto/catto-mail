<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Suppression;
use App\Suppression\SuppressionAdministration;
use App\Util\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Lists suppressions (newest first), e.g. every row that applies to one address. */
#[AsCommand('smarthost:suppression:list', 'List suppressions (operator), optionally for one address or client')]
final class SuppressionListCommand extends AdminCommand
{
    public function __construct(private readonly SuppressionAdministration $suppressions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('address', null, InputOption::VALUE_REQUIRED, 'Rows applying to this address (its address and domain rows)')
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'Rows scoped to, or reported by, this client id')
            ->addOption('active', null, InputOption::VALUE_NONE, 'Only active rows (not lifted, not expired)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows', '50');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $client = null === $input->getOption('client') ? null : $this->client((string) $input->getOption('client'));
        $rows = $this->suppressions->search($input->getOption('address'), $client, (bool) $input->getOption('active'), (int) $input->getOption('limit'));
        $io->table(['id', 'scope', 'value', 'type', 'reason', 'state', 'created_at', 'expires_at', 'lifted_at', 'provenance'],
            array_map(static fn (Suppression $s): array => [
                $s->getId()->toRfc4122(),
                null === $s->getClient() ? 'global' : 'client '.$s->getClient()->getId()->toRfc4122(),
                $s->getAddressOrDomain(),
                $s->getScopeType()->value,
                $s->getReason()->value,
                null !== $s->getLiftedAt() ? 'lifted' : ($s->isActive() ? 'active' : 'expired'),
                Clock::rfc3339($s->getCreatedAt()),
                Clock::rfc3339($s->getExpiresAt()) ?? '-',
                Clock::rfc3339($s->getLiftedAt()) ?? '-',
                match (true) {
                    null !== $s->getSourceClient() => 'reported by client '.$s->getSourceClient()->getId()->toRfc4122(),
                    null !== $s->getSourceMessage() => 'message '.$s->getSourceMessage()->getId()->toRfc4122(),
                    default => 'operator (see audit log)',
                },
            ], $rows));
        $io->writeln('rows: '.\count($rows));

        return Command::SUCCESS;
    }
}
