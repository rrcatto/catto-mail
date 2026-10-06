<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Entity\Client;
use App\Entity\ClientMembership;
use App\Entity\User;
use App\Security\ClientVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves the client named in a dashboard URL for the signed-in user. A client
 * the user may not see (no membership, insufficient role, disabled user) is
 * answered exactly like a client that does not exist: 404. Client data is then
 * loaded only with this client's id (ClientReadModel).
 */
final class ClientAccess
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function client(string $clientId, string $attribute = ClientVoter::VIEW): Client
    {
        $client = ClientReadModel::isUuid($clientId) ? $this->em->find(Client::class, Uuid::fromString($clientId)) : null;
        if (null === $client || !$this->security->isGranted($attribute, $client)) {
            throw new NotFoundHttpException('Not found.');
        }

        return $client;
    }

    public function may(string $attribute, Client $client): bool
    {
        return $this->security->isGranted($attribute, $client);
    }

    public function user(): User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : throw new \LogicException('No dashboard user.');
    }

    /** @return list<ClientMembership> the user's memberships, by client name */
    public function memberships(): array
    {
        return $this->em->createQuery('SELECT m, c FROM App\Entity\ClientMembership m JOIN m.client c WHERE m.user = :u ORDER BY c.companyName')
            ->setParameter('u', $this->user()->getId(), 'uuid')->getResult();
    }
}
