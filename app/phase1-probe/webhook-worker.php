<?php
// Phase 1 placeholder for the long-running Symfony webhook worker unit.
// Proves the unit, image, identity and database credentials; performs no
// webhook processing (Phase 7). Emits a structured heartbeat every 60 s.
declare(strict_types=1);
$check = static function (): bool {
    exec('php /srv/probe/db-check.php webhook 2>&1', $out, $rc);
    fwrite(STDOUT, json_encode(['service' => 'webhook-worker', 'msg' => 'db check', 'result' => $out[0] ?? '', 'ok' => $rc === 0]) . "\n");
    return $rc === 0;
};
pcntl_async_signals(true);
$running = true;
foreach ([SIGTERM, SIGINT] as $sig) { pcntl_signal($sig, static function () use (&$running) { $running = false; }); }
while ($running) {
    @file_put_contents('/tmp/smarthost-worker-ok', $check() ? (string) time() : '');
    for ($i = 0; $i < 60 && $running; $i++) { sleep(1); }
}
fwrite(STDOUT, json_encode(['service' => 'webhook-worker', 'msg' => 'stopping']) . "\n");
