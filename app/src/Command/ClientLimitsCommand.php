<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditActor;
use App\Client\ClientLimitAdministration;
use App\Client\ClientLimitPolicy;
use App\Client\QuotaEnforcer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows a client's limits (own, installation ceiling, effective) and the current
 * quota usage, or changes them (--set column=value, --clear column; operator with
 * PLATFORM.CLIENT_LIMIT.MANAGE, a note, audited client.limits_changed).
 */
#[AsCommand('smarthost:client:limits', 'Show or change a client\'s limits and quotas (operator; audited)')]
final class ClientLimitsCommand extends AdminCommand
{
    public function __construct(
        private readonly ClientLimitPolicy $policy,
        private readonly ClientLimitAdministration $limits,
        private readonly QuotaEnforcer $quotas,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED)
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'column=value, e.g. send_recipients_per_day=5000')
            ->addOption('clear', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'column: remove the client limit')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Login email of the operator (audit actor)')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Why (kept in the audit log)');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $client = $this->client((string) $input->getArgument('client-id'));
        $values = [];
        foreach ((array) $input->getOption('set') as $pair) {
            if (1 !== preg_match('/^([a-z_]+)=(\d{1,12})$/', (string) $pair, $m)) {
                throw new \InvalidArgumentException("--set $pair: expected column=integer.");
            }
            $values[$m[1]] = (int) $m[2];
        }
        foreach ((array) $input->getOption('clear') as $column) {
            $values[(string) $column] = null;
        }
        if ([] !== $values) {
            $operator = $this->operator($input->getOption('operator'), 'PLATFORM.CLIENT_LIMIT.MANAGE');
            $changes = $this->limits->set($client, $values, AuditActor::user($operator), (string) $input->getOption('note'));
            $io->writeln('changed: '.([] === $changes ? 'nothing' : implode(', ', array_keys($changes))));
        }
        $own = $this->policy->clientLimits($client);
        $effective = $this->policy->effective($client);
        $rows = [];
        foreach ($own as $column => $value) {
            $rows[] = [$column, $value ?? '-', $this->policy->ceiling($column) ?? '-', $effective[$column] ?? 'unlimited'];
        }
        $io->table(['limit', 'client', 'ceiling', 'effective'], $rows);
        $usage = [];
        foreach ($this->quotas->currentUsage($client) as $key => $used) {
            [$metric, $period] = explode('|', $key);
            $usage[] = [$metric, $period, $used, $this->policy->quota($client, \App\Enum\QuotaMetric::from($metric), \App\Enum\QuotaPeriod::from($period)) ?? 'unlimited'];
        }
        $io->table(['quota metric', 'period', 'used (current)', 'limit'], $usage);

        return Command::SUCCESS;
    }
}
