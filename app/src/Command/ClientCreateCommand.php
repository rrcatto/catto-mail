<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\ClientLifecycle;
use App\Enum\ClientStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a client record (Phase 9 lifecycle). By default it is pending_approval and
 * needs the service-policy acceptance before approval; --status=active creates an
 * internal client, approved at once, which is exempt from policy acceptance unless
 * --policy-acceptance=required.
 */
#[AsCommand('smarthost:client:create', 'Create a client (tenant)')]
final class ClientCreateCommand extends AdminCommand
{
    public function __construct(private readonly ClientLifecycle $lifecycle)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('company', null, InputOption::VALUE_REQUIRED, 'Organisation name')
            ->addOption('contact-email', null, InputOption::VALUE_REQUIRED, 'Primary operational contact')
            ->addOption('billing-contact-email', null, InputOption::VALUE_REQUIRED, 'Billing contact, if different')
            ->addOption('abuse-contact-email', null, InputOption::VALUE_REQUIRED, 'Abuse/security contact')
            ->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Plan name', 'standard')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'pending_approval, or active for an internal client', 'pending_approval')
            ->addOption('policy-acceptance', null, InputOption::VALUE_REQUIRED, 'required or exempt (default: required unless created active)');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $status = ClientStatus::tryFrom((string) $input->getOption('status')) ?? throw new \InvalidArgumentException('Unknown client status.');
        $policy = $input->getOption('policy-acceptance') ?? (ClientStatus::Active === $status ? 'exempt' : 'required');
        if (!\in_array($policy, ['required', 'exempt'], true)) {
            throw new \InvalidArgumentException('--policy-acceptance is required or exempt.');
        }
        $client = $this->lifecycle->create((string) $input->getOption('company'), (string) $input->getOption('contact-email'),
            (string) $input->getOption('plan'), $status, $this->actor(), 'required' === $policy,
            $input->getOption('billing-contact-email'), $input->getOption('abuse-contact-email'));
        $io->writeln('client_id: '.$client->getId()->toRfc4122());
        $io->writeln('status: '.$client->getStatus()->value);

        return Command::SUCCESS;
    }
}
