<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiProblem;
use App\Api\Cursor;
use App\Api\IdempotencyKey;
use App\Api\JsonRequest;
use App\Api\Presenter;
use App\Audit\AuditActor;
use App\Domain\DomainRuleViolation;
use App\Enum\SuppressionScopeType;
use App\Suppression\AccountBlocks;
use App\Tenant\TenantScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * OpenAPI tag Blocks (specification 2.13): the account's own blocks of an address or a
 * whole domain. Only the account's own account_block rows are ever listed or shown, never
 * other suppressions of the installation.
 */
#[Route('/v1/blocks')]
final class AccountBlockController
{
    public function __construct(
        private readonly TenantScope $tenant,
        private readonly JsonRequest $json,
        private readonly AccountBlocks $blocks,
    ) {
    }

    #[Route('', name: 'api_blocks_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = Cursor::limit($request);
        $after = Cursor::decode($request, 1);
        if (null !== $after && !Uuid::isValid($after[0])) {
            throw ApiProblem::badRequest('cursor is not valid.', 'invalid-cursor');
        }
        $status = $request->query->get('status', 'active');
        if (!\in_array($status, ['active', 'lifted', 'all'], true)) {
            throw ApiProblem::badRequest('status must be active, lifted or all.', 'invalid-query-parameter');
        }
        $value = $request->query->get('value');
        if (null !== $value) {
            try {
                $value = \is_string($value) ? AccountBlocks::parse($value)[0] : throw new DomainRuleViolation('value is not text.');
            } catch (DomainRuleViolation $e) {
                throw ApiProblem::badRequest($e->getMessage(), 'invalid-query-parameter');
            }
        }
        $rows = $this->blocks->list($this->tenant->client(), $status, $value, $after[0] ?? null, $limit + 1);
        $next = null;
        if (\count($rows) > $limit) {
            array_pop($rows);
            $next = Cursor::encode([end($rows)->getId()->toRfc4122()]);
        }

        return ApiResponse::json(Presenter::page(array_map(Presenter::accountBlock(...), $rows), $limit, $next));
    }

    #[Route('', name: 'api_blocks_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $account = $this->tenant->client();
        $key = IdempotencyKey::fromRequest($request);
        ['data' => $data, 'hash' => $hash] = $this->json->decode($request, 'AccountBlockCreateRequest');
        $hasAddress = isset($data['address']);
        if ($hasAddress === isset($data['domain'])) {
            throw ApiProblem::unprocessable('validation-error', 'Validation failed', 'Give exactly one of address and domain.',
                [['pointer' => $hasAddress ? '/domain' : '', 'message' => 'Exactly one of address and domain.']]);
        }
        try {
            $value = $hasAddress ? AccountBlocks::address($data['address']) : AccountBlocks::domain($data['domain']);
        } catch (DomainRuleViolation $e) {
            throw ApiProblem::unprocessable('validation-error', 'Validation failed', $e->getMessage(),
                [['pointer' => $hasAddress ? '/address' : '/domain', 'message' => $e->getMessage()]]);
        }
        [$block, $status, $replayed] = $this->blocks->block($account, $this->actor(), $value,
            $hasAddress ? SuppressionScopeType::Address : SuppressionScopeType::Domain, $data['external_reference'] ?? null, $key, $hash);

        $headers = ['Location' => '/v1/blocks/'.$block->getId()->toRfc4122()];
        if ($replayed) {
            $headers['Idempotent-Replayed'] = 'true';
        }

        return ApiResponse::json(Presenter::accountBlock($block), $status, $headers);
    }

    #[Route('/{id}', name: 'api_blocks_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $block = $this->blocks->find($this->tenant->client(), $id) ?? throw ApiProblem::notFound();

        return ApiResponse::json(Presenter::accountBlock($block));
    }

    #[Route('/{id}/lift', name: 'api_blocks_lift', methods: ['POST'])]
    public function lift(string $id): JsonResponse
    {
        $account = $this->tenant->client();
        $block = $this->blocks->find($account, $id) ?? throw ApiProblem::notFound();
        if (!AccountBlocks::mayLift($account)) {
            throw ApiProblem::forbidden(\sprintf('An account that is "%s" may not lift a block: lifting makes mail sendable again.', $account->getStatus()->value));
        }

        return ApiResponse::json(Presenter::accountBlock($this->blocks->lift($account, $this->actor(), $block)));
    }

    private function actor(): AuditActor
    {
        return AuditActor::apiKey($this->tenant->apiClientUser()->getApiKey());
    }
}
