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

#[AsCommand('smarthost:domain:set-status', 'Disable or re-enable a sending domain')]
final class DomainSetStatusCommand extends AdminCommand
{
    public function __construct(private readonly SendingDomainService $domains)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED)->addArgument('domain', InputArgument::REQUIRED)
            ->addArgument('status', InputArgument::REQUIRED, 'enabled or disabled');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $domain = $this->sendingDomain($this->client((string) $input->getArgument('client-id')), (string) $input->getArgument('domain'));
        match ((string) $input->getArgument('status')) {
            'disabled' => $this->domains->disable($domain, $this->actor()),
            'enabled' => $this->domains->enable($domain, $this->actor()),
            default => throw new \InvalidArgumentException('status must be enabled or disabled.'),
        };
        $io->writeln('status: '.$domain->getStatus()->value);

        return Command::SUCCESS;
    }
}
