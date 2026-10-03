<?php

declare(strict_types=1);

namespace App\Command;

use App\Webhook\WebhookEndpointService;
use App\Enum\WebhookEndpointStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('smarthost:webhook:set-status', 'Enable or disable a webhook endpoint')]
final class WebhookSetStatusCommand extends AdminCommand
{
    public function __construct(private readonly WebhookEndpointService $webhooks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('webhook-endpoint-id', InputArgument::REQUIRED)->addArgument('status', InputArgument::REQUIRED, 'enabled or disabled');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $status = WebhookEndpointStatus::tryFrom((string) $input->getArgument('status')) ?? throw new \InvalidArgumentException('status must be enabled or disabled.');
        $endpoint = $this->webhookEndpoint((string) $input->getArgument('webhook-endpoint-id'));
        $this->webhooks->setStatus($endpoint, $status, $this->actor());
        $io->writeln('status: '.$endpoint->getStatus()->value);

        return Command::SUCCESS;
    }
}
