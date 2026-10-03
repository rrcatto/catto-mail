<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiProblem;
use App\Api\Cursor;
use App\Api\IdempotencyKey;
use App\Api\JsonRequest;
use App\Api\Presenter;
use App\Entity\Message;
use App\Enum\MessageStatus;
use App\Sending\SendJobService;
use App\Tenant\TenantScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** OpenAPI tag Sending: staged ingestion (create -> recipient batches -> submit) and reads. */
#[Route('/v1/send-jobs')]
final class SendJobController
{
    public function __construct(
        private readonly TenantScope $tenant,
        private readonly JsonRequest $json,
        private readonly SendJobService $jobs,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_send_jobs_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $key = IdempotencyKey::fromRequest($request);
        ['data' => $data, 'hash' => $hash] = $this->json->decode($request, 'SendJobCreateRequest');
        [$job, $replayed] = $this->jobs->create($this->tenant->client(), $key, $hash, $data);

        $headers = ['Location' => '/v1/send-jobs/'.$job->getId()->toRfc4122()];
        if ($replayed) {
            $headers['Idempotent-Replayed'] = 'true';
        }

        return ApiResponse::json(Presenter::sendJob($job), 201, $headers);
    }

    #[Route('/{id}/recipients', name: 'api_send_jobs_recipients', methods: ['POST'])]
    public function addRecipients(string $id, Request $request): JsonResponse
    {
        $job = $this->tenant->sendJob($id) ?? throw ApiProblem::notFound();
        $key = IdempotencyKey::fromRequest($request);
        ['data' => $data, 'hash' => $hash] = $this->json->decode($request, 'RecipientBatchRequest');
        ['result' => $result, 'replayed' => $replayed] = $this->jobs->addBatch($job, $key, $hash, $data);

        return ApiResponse::json($result, 201, $replayed ? ['Idempotent-Replayed' => 'true'] : []);
    }

    #[Route('/{id}/submit', name: 'api_send_jobs_submit', methods: ['POST'])]
    public function submit(string $id): JsonResponse
    {
        $job = $this->tenant->sendJob($id) ?? throw ApiProblem::notFound();

        return ApiResponse::json(Presenter::sendJob($this->jobs->submit($job)), 202);
    }

    #[Route('/{id}', name: 'api_send_jobs_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $job = $this->tenant->sendJob($id) ?? throw ApiProblem::notFound();

        return ApiResponse::json(Presenter::sendJob($job));
    }

    /** Messages are created by the Go delivery daemon; collecting/queued jobs return an empty page. */
    #[Route('/{id}/messages', name: 'api_send_jobs_messages', methods: ['GET'])]
    public function messages(string $id, Request $request): JsonResponse
    {
        $job = $this->tenant->sendJob($id) ?? throw ApiProblem::notFound();
        $limit = Cursor::limit($request);
        $after = Cursor::decode($request, 1);
        $status = $request->query->get('current_status');
        if (null !== $status && (!\is_string($status) || null === MessageStatus::tryFrom($status))) {
            throw ApiProblem::badRequest('current_status is not a valid message status.', 'invalid-query-parameter');
        }

        $qb = $this->em->createQueryBuilder()->select('m')->from(Message::class, 'm')
            ->where('m.sendJob = :job')->setParameter('job', $job->getId(), 'uuid')
            ->orderBy('m.id', 'ASC')->setMaxResults($limit + 1);
        if (null !== $after) {
            if (!Uuid::isValid($after[0])) {
                throw ApiProblem::badRequest('cursor is not valid.', 'invalid-cursor');
            }
            $qb->andWhere('m.id > :after')->setParameter('after', Uuid::fromString($after[0]), 'uuid');
        }
        if (null !== $status) {
            $qb->andWhere('m.currentStatus = :s')->setParameter('s', $status);
        }
        $rows = $qb->getQuery()->getResult();
        $next = null;
        if (\count($rows) > $limit) {
            array_pop($rows);
            $next = Cursor::encode([end($rows)->getId()->toRfc4122()]);
        }

        return ApiResponse::json(Presenter::page(array_map(Presenter::message(...), $rows), $limit, $next));
    }
}
