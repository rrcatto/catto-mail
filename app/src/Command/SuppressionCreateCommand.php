<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\SuppressionReason;
use App\Enum\SuppressionScopeType;
use App\Suppression\SuppressionAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operator block of an address or a whole domain, global unless --client is given.
 * Only operator_block and client_abuse_block can be created here (D-30).
 */
#[AsCommand('smarthost:suppression:create', 'Create an operator suppression of an address or domain (operator)')]
final class SuppressionCreateCommand extends AdminCommand
{
    public function __construct(private readonly SuppressionAdministration $suppressions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('address', null, InputOption::VALUE_REQUIRED, 'Suppress this address (D-18 normalised)')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Suppress every address at this domain')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'operator_block or client_abuse_block', 'operator_block')
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'Scope to this client id (default: global)')
            ->addOption('expires-in-days', null, InputOption::VALUE_REQUIRED, 'Optional expiry')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Login email of the operator (audit actor)')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Why (kept in the audit log)');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $address = $input->getOption('address');
        $domain = $input->getOption('domain');
        if ((null === $address) === (null === $domain)) {
            throw new \InvalidArgumentException('Give exactly one of --address or --domain.');
        }
        $reason = SuppressionReason::tryFrom((string) $input->getOption('reason')) ?? throw new \InvalidArgumentException('Unknown suppression reason.');
        $client = null === $input->getOption('client') ? null : $this->client((string) $input->getOption('client'));
        $days = $input->getOption('expires-in-days');
        $s = $this->suppressions->create($client, (string) ($address ?? $domain),
            null !== $address ? SuppressionScopeType::Address : SuppressionScopeType::Domain, $reason,
            null === $days ? null : (int) $days, $this->operator($input->getOption('operator'), 'PLATFORM.SUPPRESSION.MANAGE'), (string) $input->getOption('note'));
        $io->writeln('suppression_id: '.$s->getId()->toRfc4122());
        $io->writeln('value: '.$s->getAddressOrDomain());

        return Command::SUCCESS;
    }
}
