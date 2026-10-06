<?php

declare(strict_types=1);

namespace App\Command;

use App\Version;
use App\Webhook\WebhookDispatcher;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The Symfony webhook worker (D-22): the only process that sends webhooks. Runs in
 * the smarthost-webhook-worker container as the smarthost_webhook database role.
 *
 * Loop: fan out pending outbox events, fail deliveries whose final lease expired,
 * claim and send due deliveries, record a heartbeat; when idle, wait for
 * NOTIFY smarthost_webhook_work or APP_WEBHOOK_POLL_INTERVAL_SECONDS. SIGTERM/SIGINT
 * stop claiming new work; requests already sent finish within the HTTP timeout,
 * and anything unrecorded is re-claimed by the next worker after its lease.
 *
 * Liveness: the file HEALTH_FILE is touched every loop iteration (the container
 * health check requires it to be recent), and webhook_worker_heartbeats holds
 * this process's row for the operator dashboard.
 */
#[AsCommand('smarthost:webhook:work', 'Run the webhook worker (fan-out, signed HTTP delivery, retries)')]
final class WebhookWorkCommand extends Command
{
    public const HEALTH_FILE = '/tmp/smarthost-webhook-worker.alive';
    private bool $stopping = false;

    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        #[Autowire(service: 'doctrine.dbal.webhook_connection')] private readonly Connection $db,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.webhook.poll_interval_seconds%')] private readonly int $pollInterval,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Process what is due once and exit (tests, diagnostics)')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Deliveries claimed per round', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workerId = gethostname().':'.getmypid().':'.bin2hex(random_bytes(4));
        $batch = max(1, min(50, (int) $input->getOption('batch')));
        pcntl_async_signals(true);
        foreach ([\SIGTERM, \SIGINT] as $signal) {
            pcntl_signal($signal, function () use ($workerId): void {
                $this->stopping = true;
                $this->logger->info('Webhook worker stopping: no new claims.', ['worker_id' => $workerId]);
            });
        }
        $this->db->executeStatement(<<<'SQL'
            INSERT INTO webhook_worker_heartbeats (worker_id, version, started_at, last_seen_at) VALUES (?, ?, now(), now())
            SQL, [$workerId, Version::VERSION]);
        $this->logger->info('Webhook worker started.', ['worker_id' => $workerId, 'version' => Version::VERSION]);
        $listener = $input->getOption('once') ? null : $this->listen();
        $lastHeartbeat = 0;
        do {
            @touch(self::HEALTH_FILE);
            $busy = false;
            try {
                $busy = $this->dispatcher->fanOut() > 0;
                $this->dispatcher->failExhaustedLeases();
                if (!$this->stopping) {
                    $claims = $this->dispatcher->claim($workerId, $batch);
                    if ([] !== $claims) {
                        $busy = true;
                        $this->dispatcher->attempt($workerId, $claims);
                    }
                }
            } catch (\Throwable $e) {
                // A database outage or bug must not kill the loop; work stays in PostgreSQL.
                $this->logger->error('Webhook worker round failed.', ['worker_id' => $workerId, 'exception' => $e::class, 'error' => mb_substr($e->getMessage(), 0, 300)]);
                sleep(1);
            }
            if (time() - $lastHeartbeat >= 5) {
                $this->heartbeat($workerId);
                $lastHeartbeat = time();
            }
            if (!$busy && !$this->stopping && !$input->getOption('once')) {
                $this->wait($listener);
            }
        } while (!$this->stopping && !$input->getOption('once'));
        try {
            $this->db->executeStatement('UPDATE webhook_worker_heartbeats SET stopped_at = now(), last_seen_at = now() WHERE worker_id = ?', [$workerId]);
            $this->heartbeat($workerId);
        } catch (\Throwable $e) {
            // A whole-pod stop may stop PostgreSQL first: the row then shows as stale (no
            // heartbeat for 2 minutes) instead of stopped. Nothing else depends on it.
            $this->logger->warning('Webhook worker stop not recorded.', ['worker_id' => $workerId, 'exception' => $e::class]);
        }
        $this->logger->info('Webhook worker stopped.', ['worker_id' => $workerId] + $this->dispatcher->stats);

        return Command::SUCCESS;
    }

    private function heartbeat(string $workerId): void
    {
        try {
            $s = $this->dispatcher->stats;
            $this->db->executeStatement(<<<'SQL'
                UPDATE webhook_worker_heartbeats SET last_seen_at = now(), attempts = ?, delivered = ?, events_fanned_out = ? WHERE worker_id = ?
                SQL, [$s['attempts'], $s['delivered'], $s['events_fanned_out'], $workerId]);
        } catch (\Throwable $e) {
            $this->logger->warning('Webhook worker heartbeat failed.', ['worker_id' => $workerId, 'exception' => $e::class]);
        }
    }

    /** A dedicated LISTEN connection with the worker's own credentials; null when unavailable (polling only). */
    private function listen(): ?\Pdo\Pgsql
    {
        try {
            $p = $this->db->getParams();
            $pdo = \Pdo\Pgsql::connect(\sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $p['host'], $p['port'], $p['dbname'], $p['sslmode'] ?? 'prefer'),
                $p['user'], $p['password']);
            $pdo->exec('LISTEN '.WebhookDispatcher::NOTIFY_CHANNEL);

            return $pdo instanceof \Pdo\Pgsql ? $pdo : null;
        } catch (\Throwable $e) {
            $this->logger->warning('LISTEN unavailable; polling only.', ['exception' => $e::class]);

            return null;
        }
    }

    private function wait(?\Pdo\Pgsql $listener): void
    {
        $deadline = microtime(true) + max(1, $this->pollInterval);
        while (!$this->stopping && microtime(true) < $deadline) {
            @touch(self::HEALTH_FILE);
            if (null !== $listener) {
                if (false !== $listener->getNotify(\PDO::FETCH_ASSOC, 1000)) {
                    return;
                }
            } else {
                usleep(250000);
            }
        }
    }
}
