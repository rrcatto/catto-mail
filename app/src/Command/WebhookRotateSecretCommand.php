<?php

declare(strict_types=1);

namespace App\Command;

use App\Webhook\WebhookEndpointService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('smarthost:webhook:rotate-secret', 'Rotate a webhook signing secret (previous one stays valid for the overlap window)')]
final class WebhookRotateSecretCommand extends AdminCommand
{
    public function __construct(private readonly WebhookEndpointService $webhooks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('webhook-endpoint-id', InputArgument::REQUIRED);
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $endpoint = $this->webhookEndpoint((string) $input->getArgument('webhook-endpoint-id'));
        $secret = $this->webhooks->rotateSecret($endpoint, $this->actor());
        $io->writeln('signing_secret: '.$secret);
        $io->writeln('previous_secret_expires_at: '.$endpoint->getPreviousSigningSecretExpiresAt()?->format(\DATE_RFC3339_EXTENDED));

        return Command::SUCCESS;
    }
}
