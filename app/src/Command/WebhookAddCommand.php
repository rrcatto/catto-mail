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

#[AsCommand('smarthost:webhook:add', 'Register a webhook endpoint and print its signing secret once')]
final class WebhookAddCommand extends AdminCommand
{
    public function __construct(private readonly WebhookEndpointService $webhooks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED)->addArgument('url', InputArgument::REQUIRED)
            ->addOption('event', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Subscribed event type (repeatable)');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        [$endpoint, $secret] = $this->webhooks->create($this->client((string) $input->getArgument('client-id')),
            (string) $input->getArgument('url'), array_values((array) $input->getOption('event')), $this->actor());
        $io->writeln('webhook_endpoint_id: '.$endpoint->getId()->toRfc4122());
        $io->writeln('signing_secret: '.$secret);
        $io->note('The signing secret is shown once; it is stored only encrypted.');

        return Command::SUCCESS;
    }
}
