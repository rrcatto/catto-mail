<?php

declare(strict_types=1);

namespace App\Command;

use App\Dashboard\OperatorReadModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The operator's production health summary on the host (Phase 8,
 * `smarthostctl prod ops-status`): the same durable signals as the operator
 * dashboard overview, as text or JSON (for monitoring scripts). Read-only.
 */
#[AsCommand('smarthost:ops:status', 'Production health summary: backlogs, delivery state, Postfix queue, rates, suppressions, DSNs, webhooks')]
final class OpsStatusCommand extends Command
{
    public function __construct(private readonly OperatorReadModel $read)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $o = $this->read->overview();
        $delivery = array_values(array_filter($o['delivery'], static fn (array $d): bool => (bool) $d['alive']));
        $d = $delivery[0] ?? null;
        $rates = array_map(static fn (array $r): array => array_intersect_key($r, array_flip(
            ['company_name', 'client_status', 'reached_postfix', 'hard_bounce_rate', 'soft_bounce_rate', 'deferral_rate', 'complaint_rate', 'outcome_unknown'])), $o['rates']);
        $summary = [
            'validation' => ['active_jobs' => (int) $o['validation']['active_jobs'], 'pending_addresses' => (int) $o['validation']['pending'],
                'retry_scheduled' => (int) $o['validation']['retry_scheduled'], 'active_workers' => (int) $o['validation']['active_workers']],
            'sending' => ['queued_jobs' => (int) $o['sending']['queued_jobs'], 'processing_jobs' => (int) $o['sending']['processing_jobs'],
                'dispatched_jobs' => (int) $o['sending']['dispatched_jobs'], 'active_workers' => (int) $o['sending']['active_workers']],
            'messages_by_status' => $o['statuses'],
            'outcome_unknown' => (int) ($o['statuses']['outcome_unknown'] ?? 0),
            'delivery' => null === $d ? null : [
                'state' => $d['outbound_paused'] ? 'paused' : ($d['send_work_held'] ? 'held' : ($d['live_delivery'] ? 'live' : 'capture')),
                'global_rate_per_minute' => (int) $d['global_rate_per_minute'], 'version' => $d['version'],
                'postfix_queue' => null === $d['queue_snapshot_at'] ? null : ['active' => (int) $d['queue_active'], 'deferred' => (int) $d['queue_deferred'],
                    'hold' => (int) $d['queue_hold'], 'incoming' => (int) $d['queue_incoming'], 'snapshot_at' => $d['queue_snapshot_at'],
                    'snapshot_fresh' => (bool) $d['snapshot_fresh']],
            ],
            'rates_window_days' => $o['rate_window_days'],
            'rates_by_client' => $rates,
            'global_suppressions' => $o['global_suppressions'],
            'suppressions_by_reason' => array_map('intval', $o['suppressions']),
            'unmatched_dsns' => $o['unmatched_dsns'],
            'webhooks' => ['events_awaiting_fanout' => $o['webhooks']['events_awaiting_fanout'], 'deliveries' => $o['webhooks']['deliveries'],
                'workers_running' => \count(array_filter($o['webhooks']['workers'], static fn (array $w): bool => (bool) $w['alive']))],
            'clients' => $o['clients'],
        ];
        if ($input->getOption('json')) {
            $output->writeln(json_encode($summary, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }
        $line = static fn (string $k, mixed $v) => $output->writeln(\sprintf('%-34s %s', $k, \is_scalar($v) || null === $v ? var_export($v, true) : json_encode($v, \JSON_UNESCAPED_SLASHES)));
        $line('validation backlog (addresses)', $summary['validation']['pending_addresses'] + $summary['validation']['retry_scheduled']);
        $line('validation workers', $summary['validation']['active_workers']);
        $line('send jobs queued / processing', $summary['sending']['queued_jobs'].' / '.$summary['sending']['processing_jobs']);
        $line('send jobs dispatched (awaiting)', $summary['sending']['dispatched_jobs']);
        $line('messages by status', $summary['messages_by_status']);
        $line('outcome_unknown', $summary['outcome_unknown']);
        $line('delivery', $summary['delivery'] ?? 'NO DELIVERY DAEMON SEEN IN 2 MINUTES');
        foreach ($rates as $r) {
            $line('rates '.$r['company_name'].' ('.$r['client_status'].')', array_diff_key($r, ['company_name' => 1, 'client_status' => 1]));
        }
        $line('global suppressions', $summary['global_suppressions']);
        $line('suppressions by reason', $summary['suppressions_by_reason']);
        $line('unmatched DSNs', $summary['unmatched_dsns']);
        $line('webhooks', $summary['webhooks']);
        $line('clients by status', $summary['clients']);

        return Command::SUCCESS;
    }
}
