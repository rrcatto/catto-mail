<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness through nginx -> FastCGI -> PHP-FPM -> Symfony (supersedes the Phase 1
 * probe at the same path). Deliberately exposes no configuration, database or
 * secret information and does not touch the database.
 */
final class HealthController
{
    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'component' => 'symfony-app (php-fpm)', 'sapi' => \PHP_SAPI],
            200, ['Cache-Control' => 'no-store']);
    }
}
