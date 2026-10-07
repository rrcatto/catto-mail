<?php

declare(strict_types=1);

namespace App\Command;

use App\Usage\UsageReporting;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Usage of one client or of every client for a period, unit by unit (Phase 9). */
#[AsCommand('smarthost:usage:summary', 'Usage per client and usage type for a period')]
final class UsageSummaryCommand extends AdminCommand
{
    public function __construct(private readonly UsageReporting $reporting)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'One client id (default: every client)')
            ->addOption('json', null, InputOption::VALUE_NONE);
        $this->addPeriodOptions();
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $period = $this->usagePeriod($input);
        if (null !== $input->getOption('client')) {
            $client = $this->client((string) $input->getOption('client'));
            $data = ['client_id' => $client->getId()->toRfc4122(), 'period' => $period->asArray(),
                'totals' => $this->reporting->clientTotals($client->getId()->toRfc4122(), $period)];
            $rows = [];
            foreach ($data['totals'] as $type => $t) {
                $rows[] = [$type, $t['quantity'], $t['records']];
            }
        } else {
            $data = ['period' => $period->asArray(), 'clients' => []];
            $after = null;
            do {
                $page = $this->reporting->allClients($period, 500, $after);
                array_push($data['clients'], ...$page);
                $after = [] === $page ? null : end($page)['company_name'];
            } while (500 === \count($page));
            $rows = array_map(static fn (array $c): array => [$c['company_name'], $c['status'], $c['validation_address'], $c['message_submitted']], $data['clients']);
        }
        if ($input->getOption('json')) {
            $io->writeln((string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        } else {
            $io->writeln(\sprintf('period: %s to %s (exclusive)', $data['period']['start'], $data['period']['end']));
            $io->table(null !== $input->getOption('client') ? ['usage type', 'quantity', 'records'] : ['client', 'status', 'validation addresses', 'messages submitted'], $rows);
        }

        return Command::SUCCESS;
    }
}
