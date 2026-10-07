<?php

declare(strict_types=1);

namespace App\Command;

use App\Usage\UsageReconciliation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reconciles metered usage against the authoritative validation jobs and messages
 * (Phase 9). Exit status 1 when any client is inconsistent, so it can run from a timer
 * or a monitoring check.
 */
#[AsCommand('smarthost:usage:reconcile', 'Reconcile usage against validation jobs and messages for a period')]
final class UsageReconcileCommand extends AdminCommand
{
    public function __construct(private readonly UsageReconciliation $reconciliation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'One client id (default: every client with usage in the period)')
            ->addOption('json', null, InputOption::VALUE_NONE);
        $this->addPeriodOptions();
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $period = $this->usagePeriod($input);
        $clients = null !== $input->getOption('client')
            ? [$this->client((string) $input->getOption('client'))->getId()->toRfc4122()]
            : $this->em->getConnection()->fetchFirstColumn(
                'SELECT DISTINCT client_id::text FROM usage_records WHERE occurred_at >= ? AND occurred_at < ? ORDER BY 1',
                [$period->startSql(), $period->endSql()]);
        $results = array_map(fn (string $id): array => $this->reconciliation->reconcile($id, $period), $clients);
        $inconsistent = array_filter($results, static fn (array $r): bool => 'consistent' !== $r['status']);
        if ($input->getOption('json')) {
            $io->writeln((string) json_encode(['period' => $period->asArray(), 'results' => $results], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        } else {
            $io->table(['client', 'status', 'validation jobs', 'message usage', 'messages accepted', 'findings'], array_map(static fn (array $r): array => [
                $r['client_id'], $r['status'], $r['checked']['validation_jobs'], $r['checked']['message_usage_records'], $r['checked']['messages_accepted'], $r['findings_total']], $results));
            foreach ($inconsistent as $r) {
                foreach ($r['findings'] as $f) {
                    $io->writeln($r['client_id'].': '.json_encode($f, \JSON_UNESCAPED_SLASHES));
                }
            }
        }

        return [] === $inconsistent ? Command::SUCCESS : Command::FAILURE;
    }
}
