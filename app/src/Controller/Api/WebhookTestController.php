<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /v1/webhooks/test (x-smarthost-available-from-phase: 7). Until webhook
 * delivery exists the contract requires 501; the outbox foundation that Phase 7
 * builds on is App\Webhook\WebhookOutbox.
 */
final class WebhookTestController
{
    #[Route('/v1/webhooks/test', name: 'api_webhooks_test', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        throw ApiProblem::notImplemented('Webhook delivery is not implemented yet (available from Phase 7).');
    }
}
