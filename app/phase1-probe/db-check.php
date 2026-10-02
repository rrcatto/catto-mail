<?php
// Phase 1 CLI probe: connect to PostgreSQL as a given Smarthost role and
// verify its privilege boundary. Usage: php db-check.php app|webhook|owner
// Credentials come from the contract variables of the calling container.
declare(strict_types=1);
$roles = [
    'app'     => ['APP_DB_USER', 'APP_DB_PASSWORD', false],
    'webhook' => ['APP_WEBHOOK_DB_USER', 'APP_WEBHOOK_DB_PASSWORD', false],
    'owner'   => ['SMARTHOST_DB_OWNER_USER', 'SMARTHOST_DB_OWNER_PASSWORD', true],
];
$which = $argv[1] ?? 'app';
[$userVar, $passVar, $mayCreate] = $roles[$which] ?? exit("unknown role $which\n");
$env = static function (string $name): string {
    $file = getenv($name . '_FILE');
    $value = $file !== false && $file !== '' ? trim((string) file_get_contents($file)) : getenv($name);
    if ($value === false || $value === '') { fwrite(STDERR, "missing $name\n"); exit(2); }
    return $value;
};
$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $env('SMARTHOST_DB_HOST'),
    $env('SMARTHOST_DB_PORT'), $env('SMARTHOST_DB_NAME'), $env('SMARTHOST_DB_SSLMODE'));
$pdo = new PDO($dsn, $env($userVar), $env($passVar), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$user = $pdo->query('SELECT current_user')->fetchColumn();
$canCreate = true;
try {
    $pdo->beginTransaction();
    $pdo->exec('CREATE TABLE smarthost_phase1_privilege_probe (id int)');
} catch (PDOException) {
    $canCreate = false;
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}
$ok = $canCreate === $mayCreate;
printf("role=%s connected_as=%s can_create_table=%s expected=%s => %s\n", $which, $user,
    $canCreate ? 'yes' : 'no', $mayCreate ? 'yes' : 'no', $ok ? 'OK' : 'VIOLATION');
exit($ok ? 0 : 1);
