<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiProblem;
use App\Api\JsonRequest;
use App\Audit\AuditActor;
use App\Tenant\TenantScope;
use App\Webhook\WebhookTestService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /v1/webhooks/test: records a `webhook.test` event for exactly one of the
 * client's endpoints (`webhook_endpoint_id`, required by the contract) and answers
 * 202 with the event id at once. The Symfony webhook worker delivers it through the
 * normal outbox path; this request never calls the endpoint. 404 for an endpoint
 * that is not this client's; 409 `webhook-endpoint-disabled` for a disabled one.
 */
final class WebhookTestController
{
    public function __construct(
        private readonly TenantScope $tenant,
        private readonly JsonRequest $json,
        private readonly WebhookTestService $tests,
    ) {
    }

    #[Route('/v1/webhooks/test', name: 'api_webhooks_test', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $client = $this->tenant->client();
        $data = $this->json->decode($request, 'WebhookTestRequest')['data'];
        $endpoint = $this->tenant->webhookEndpoint((string) $data['webhook_endpoint_id']) ?? throw ApiProblem::notFound();
        $id = $this->tests->request($client, $endpoint, AuditActor::apiKey($this->tenant->apiClientUser()->getApiKey()))
            ?? throw ApiProblem::conflict('webhook-endpoint-disabled', 'Webhook endpoint disabled',
                'This webhook endpoint is disabled; enable it to send a test event.');

        return ApiResponse::json(['webhook_event_id' => $id->toRfc4122()], 202);
    }
}
