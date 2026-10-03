<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiProblem;
use App\Api\Cursor;
use App\Api\IdempotencyKey;
use App\Api\JsonRequest;
use App\Api\Presenter;
use App\Entity\ValidationAddress;
use App\Enum\OverallClassification;
use App\Tenant\TenantScope;
use App\Validation\ValidationJobService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** OpenAPI tag Validation. */
#[Route('/v1/validation-jobs')]
final class ValidationJobController
{
    public function __construct(
        private readonly TenantScope $tenant,
        private readonly JsonRequest $json,
        private readonly ValidationJobService $jobs,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_validation_jobs_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $key = IdempotencyKey::fromRequest($request);
        ['data' => $data, 'hash' => $hash] = $this->json->decode($request, 'ValidationJobCreateRequest');
        [$job, $replayed] = $this->jobs->create($this->tenant->client(), $key, $hash, $data);

        $headers = ['Location' => '/v1/validation-jobs/'.$job->getId()->toRfc4122()];
        if ($replayed) {
            $headers['Idempotent-Replayed'] = 'true';
        }

        return ApiResponse::json(Presenter::validationJob($job), 202, $headers);
    }

    #[Route('/{id}', name: 'api_validation_jobs_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $job = $this->tenant->validationJob($id) ?? throw ApiProblem::notFound();

        return ApiResponse::json(Presenter::validationJob($job));
    }

    /** Ordered by address id ascending = submission order (monotonic UUIDv7). */
    #[Route('/{id}/addresses', name: 'api_validation_jobs_addresses', methods: ['GET'])]
    public function addresses(string $id, Request $request): JsonResponse
    {
        $job = $this->tenant->validationJob($id) ?? throw ApiProblem::notFound();
        $limit = Cursor::limit($request);
        $after = Cursor::decode($request, 1);
        $filter = $request->query->get('overall_classification');
        if (null !== $filter && (!\is_string($filter) || null === OverallClassification::tryFrom($filter))) {
            throw ApiProblem::badRequest('overall_classification is not a valid classification.', 'invalid-query-parameter');
        }

        $qb = $this->em->createQueryBuilder()->select('a')->from(ValidationAddress::class, 'a')
            ->where('a.job = :job')->setParameter('job', $job->getId(), 'uuid')
            ->orderBy('a.id', 'ASC')->setMaxResults($limit + 1);
        if (null !== $after) {
            if (!Uuid::isValid($after[0])) {
                throw ApiProblem::badRequest('cursor is not valid.', 'invalid-cursor');
            }
            $qb->andWhere('a.id > :after')->setParameter('after', Uuid::fromString($after[0]), 'uuid');
        }
        if (null !== $filter) {
            $qb->andWhere('a.overallClassification = :c')->setParameter('c', $filter);
        }
        $rows = $qb->getQuery()->getResult();
        $next = null;
        if (\count($rows) > $limit) {
            array_pop($rows);
            $next = Cursor::encode([end($rows)->getId()->toRfc4122()]);
        }

        return ApiResponse::json(Presenter::page(array_map(Presenter::validationAddress(...), $rows), $limit, $next));
    }
}
