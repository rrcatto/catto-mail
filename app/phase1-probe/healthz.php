<?php
// Phase 1 infrastructure probe served through nginx -> FastCGI -> PHP-FPM.
// Deliberately exposes no configuration, database or secret information.
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode([
    'status' => 'ok',
    'component' => 'symfony-app (php-fpm)',
    'sapi' => PHP_SAPI,
    'phase' => 1,
]), "\n";
