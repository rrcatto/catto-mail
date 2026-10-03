<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\SendingDomainService;
use App\Enum\DkimStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('smarthost:domain:dkim', 'Record the DKIM selector/status of a sending domain (keys are managed by OpenDKIM)')]
final class DomainDkimCommand extends AdminCommand
{
    public function __construct(private readonly SendingDomainService $domains)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED)->addArgument('domain', InputArgument::REQUIRED)
            ->addArgument('dkim-status', InputArgument::REQUIRED, 'not_configured, pending_dns, active or disabled')
            ->addOption('selector', null, InputOption::VALUE_REQUIRED);
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $status = DkimStatus::tryFrom((string) $input->getArgument('dkim-status')) ?? throw new \InvalidArgumentException('Unknown DKIM status.');
        $domain = $this->sendingDomain($this->client((string) $input->getArgument('client-id')), (string) $input->getArgument('domain'));
        $this->domains->setDkim($domain, $status, $input->getOption('selector'), $this->actor());
        $io->writeln('dkim_status: '.$domain->getDkimStatus()->value.' selector: '.($domain->getDkimSelector() ?? '-'));

        return Command::SUCCESS;
    }
}
