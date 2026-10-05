<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiProblem;
use App\Api\IdempotencyKey;
use App\Api\JsonRequest;
use App\Api\Presenter;
use App\Audit\AuditActor;
use App\Suppression\GlobalSuppressionService;
use App\Tenant\TenantScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OpenAPI tag Suppressions: recipient global opt-outs reported by trusted clients
 * (D-30). The representation shows only the client's own opt-out, never other
 * clients' or system suppressions of the address.
 */
#[Route('/v1/global-suppressions')]
final class GlobalSuppressionController
{
    public function __construct(
        private readonly TenantScope $tenant,
        private readonly JsonRequest $json,
        private readonly GlobalSuppressionService $suppressions,
    ) {
    }

    #[Route('', name: 'api_global_suppressions_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $client = $this->tenant->client();
        GlobalSuppressionService::assertMayReport($client);
        $key = IdempotencyKey::fromRequest($request);
        ['data' => $data, 'hash' => $hash] = $this->json->decode($request, 'GlobalOptOutCreateRequest');
        [$optOut, $status, $replayed] = $this->suppressions->create($client, $this->actor(), $key, $hash, $data);

        $headers = ['Location' => '/v1/global-suppressions/'.$optOut->getId()->toRfc4122()];
        if ($replayed) {
            $headers['Idempotent-Replayed'] = 'true';
        }

        return ApiResponse::json(Presenter::globalOptOut($optOut), $status, $headers);
    }

    #[Route('/{id}', name: 'api_global_suppressions_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $optOut = $this->suppressions->ownOptOut($this->tenant->client(), $id) ?? throw ApiProblem::notFound();

        return ApiResponse::json(Presenter::globalOptOut($optOut));
    }

    #[Route('/{id}/lift', name: 'api_global_suppressions_lift', methods: ['POST'])]
    public function lift(string $id): JsonResponse
    {
        $client = $this->tenant->client();
        GlobalSuppressionService::assertMayReport($client);
        $optOut = $this->suppressions->ownOptOut($client, $id) ?? throw ApiProblem::notFound();

        return ApiResponse::json(Presenter::globalOptOut($this->suppressions->lift($client, $this->actor(), $optOut)));
    }

    private function actor(): AuditActor
    {
        return AuditActor::apiKey($this->tenant->apiClientUser()->getApiKey());
    }
}
