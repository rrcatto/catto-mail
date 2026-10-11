<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiProblem;
use App\Api\JsonRequest;
use App\Audit\AuditActor;
use App\Connect\ConnectFailure;
use App\Connect\ConnectService;
use App\Tenant\TenantScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OpenAPI tag Connect (specification 2.12): the server-to-server half of connecting an
 * application.
 *
 *   POST   /v1/connect/exchange   the only /v1 operation without an API key - the application
 *                                 has none yet. It presents the single-use code from its
 *                                 redirect, its PKCE code verifier and the redirect URI, and
 *                                 receives its credentials once. Limited per client address.
 *   DELETE /v1/connection         the connected application ends its own connection with the
 *                                 API key that connection issued: the key is revoked and the
 *                                 webhook endpoint removed.
 *
 * Nothing secret is logged: neither the code, the verifier, the key nor the signing secret.
 */
final class ConnectController
{
    public const EXCHANGE_PATH = '/v1/connect/exchange';

    public function __construct(
        private readonly ConnectService $connect,
        private readonly JsonRequest $json,
        private readonly TenantScope $tenant,
        private readonly RateLimiterFactoryInterface $connectExchangeLimiter,
    ) {
    }

    #[Route(self::EXCHANGE_PATH, name: 'api_connect_exchange', methods: ['POST'])]
    public function exchange(Request $request): JsonResponse
    {
        $limit = $this->connectExchangeLimiter->create($request->getClientIp() ?? 'unknown')->consume(1);
        if (!$limit->isAccepted()) {
            throw ApiProblem::rateLimited(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
        $data = $this->json->decode($request, 'ConnectExchangeRequest')['data'];
        try {
            $credentials = $this->connect->exchange((string) $data['code'], (string) $data['code_verifier'], (string) $data['redirect_uri']);
        } catch (ConnectFailure $e) {
            throw $e->invalidCode
                ? ApiProblem::badRequest($e->getMessage(), 'invalid-connect-code')
                : ApiProblem::conflict('connect-refused', 'Connection refused', $e->getMessage());
        }

        return ApiResponse::json($credentials, 200, ['Pragma' => 'no-cache']);
    }

    #[Route('/v1/connection', name: 'api_connection_delete', methods: ['DELETE'])]
    public function disconnect(): Response
    {
        $key = $this->tenant->apiClientUser()->getApiKey();
        // A key created in the dashboard belongs to no connection: there is nothing to end.
        $connection = $this->connect->forApiKey($key) ?? throw ApiProblem::notFound();
        $this->connect->revoke($connection, AuditActor::apiKey($key), 'application');

        return new Response('', Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
    }
}
