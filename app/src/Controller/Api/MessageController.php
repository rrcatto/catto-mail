<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiProblem;
use App\Api\Cursor;
use App\Api\Presenter;
use App\Entity\MessageEvent;
use App\Tenant\TenantScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** OpenAPI tag Messages: the append-only event history of one message. */
final class MessageController
{
    public function __construct(
        private readonly TenantScope $tenant,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Ordered by occurred_at ascending, then id; the cursor carries both. */
    #[Route('/v1/messages/{id}/events', name: 'api_message_events', methods: ['GET'])]
    public function events(string $id, Request $request): JsonResponse
    {
        $message = $this->tenant->message($id) ?? throw ApiProblem::notFound();
        $limit = Cursor::limit($request);
        $after = Cursor::decode($request, 2);

        $qb = $this->em->createQueryBuilder()->select('e')->from(MessageEvent::class, 'e')
            ->where('e.message = :m')->setParameter('m', $message->getId(), 'uuid')
            ->orderBy('e.occurredAt', 'ASC')->addOrderBy('e.id', 'ASC')->setMaxResults($limit + 1);
        if (null !== $after) {
            try {
                $occurred = new \DateTimeImmutable($after[0]);
            } catch (\Exception) {
                $occurred = null;
            }
            if (null === $occurred || !Uuid::isValid($after[1])) {
                throw ApiProblem::badRequest('cursor is not valid.', 'invalid-cursor');
            }
            $qb->andWhere('e.occurredAt > :t OR (e.occurredAt = :t AND e.id > :id)')
                ->setParameter('t', $occurred, 'timestamptz')->setParameter('id', Uuid::fromString($after[1]), 'uuid');
        }
        $rows = $qb->getQuery()->getResult();
        $next = null;
        if (\count($rows) > $limit) {
            array_pop($rows);
            $last = end($rows);
            $next = Cursor::encode([$last->getOccurredAt()->format('Y-m-d\TH:i:s.uP'), $last->getId()->toRfc4122()]);
        }

        return ApiResponse::json(Presenter::page(array_map(Presenter::messageEvent(...), $rows), $limit, $next));
    }
}
