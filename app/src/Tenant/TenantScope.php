<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Entity\Client;
use App\Entity\Message;
use App\Entity\SendingDomain;
use App\Entity\SendJob;
use App\Entity\ValidationJob;
use App\Entity\WebhookEndpoint;
use App\Security\ApiClientUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * The only way API code loads tenant-owned resources. Every lookup carries the
 * client of the authenticated API key; a resource of another client (or a
 * malformed id) is indistinguishable from a missing one (null -> 404).
 */
final class TenantScope
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function client(): Client
    {
        $user = $this->security->getUser();
        if (!$user instanceof ApiClientUser) {
            throw new \LogicException('No authenticated API client.');
        }

        return $user->getClient();
    }

    public function apiClientUser(): ApiClientUser
    {
        $user = $this->security->getUser();

        return $user instanceof ApiClientUser ? $user : throw new \LogicException('No authenticated API client.');
    }

    public function validationJob(string $id): ?ValidationJob
    {
        return $this->owned(ValidationJob::class, $id);
    }

    public function sendJob(string $id): ?SendJob
    {
        return $this->owned(SendJob::class, $id);
    }

    public function sendingDomain(string $id): ?SendingDomain
    {
        return $this->owned(SendingDomain::class, $id);
    }

    public function webhookEndpoint(string $id): ?WebhookEndpoint
    {
        return $this->owned(WebhookEndpoint::class, $id);
    }

    public function message(string $id): ?Message
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->em->createQuery(
            'SELECT m FROM App\Entity\Message m JOIN m.sendJob j WHERE m.id = :id AND j.client = :client')
            ->setParameter('id', Uuid::fromString($id), 'uuid')
            ->setParameter('client', $this->client()->getId(), 'uuid')
            ->getOneOrNullResult();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function owned(string $class, string $id): ?object
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->em->createQuery("SELECT e FROM $class e WHERE e.id = :id AND e.client = :client")
            ->setParameter('id', Uuid::fromString($id), 'uuid')
            ->setParameter('client', $this->client()->getId(), 'uuid')
            ->getOneOrNullResult();
    }
}
