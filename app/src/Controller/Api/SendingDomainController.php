<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Connect\ConnectService;
use App\Tenant\TenantScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /v1/sending-domains (specification 2.12): the authenticated client's sending domains and
 * their state, read-only, so that an application can warn before it sends from a domain that
 * cannot send yet. `can_send` is the submit rule (App\Sending\SendingDomainRule) on the domain as
 * it is now. Domains are managed in the dashboard; a client has at most its domain limit of them,
 * so the list is not paginated.
 */
final class SendingDomainController
{
    public function __construct(
        private readonly TenantScope $tenant,
        private readonly ConnectService $connect,
    ) {
    }

    #[Route('/v1/sending-domains', name: 'api_sending_domains_list', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return ApiResponse::json(['data' => $this->connect->sendingDomains($this->tenant->client())]);
    }
}
