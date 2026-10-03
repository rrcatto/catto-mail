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

#[AsCommand('smarthost:domain:verify', 'Check the DNS TXT challenge of one domain, or (--due) every pending domain due for a re-check')]
final class DomainVerifyCommand extends AdminCommand
{
    public function __construct(private readonly SendingDomainService $domains)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::OPTIONAL)->addArgument('domain', InputArgument::OPTIONAL)
            ->addOption('due', null, InputOption::VALUE_NONE, 'Re-check all pending domains (scheduled; APP_DOMAIN_VERIFICATION_RECHECK_HOURS)');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        if ($input->getOption('due')) {
            $verified = 0;
            $due = $this->domains->dueForRecheck();
            foreach ($due as $domain) {
                $verified += $this->domains->verify($domain, $this->actor()) ? 1 : 0;
            }
            $io->writeln(\sprintf('checked: %d verified: %d', \count($due), $verified));

            return Command::SUCCESS;
        }
        $domain = $this->sendingDomain($this->client((string) $input->getArgument('client-id')), (string) $input->getArgument('domain'));
        $ok = $this->domains->verify($domain, $this->actor());
        $io->writeln('status: '.$domain->getStatus()->value.($ok ? '' : ' ('.$domain->getLastCheckError().')'));

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
