<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Security\ApiClientUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/** Turns on App\Tenant\TenantFilter for the authenticated API client of this request. */
#[AsEventListener]
final class TenantFilterSubscriber
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ('api' !== $event->getFirewallName() || !$user instanceof ApiClientUser) {
            return;
        }
        $filters = $this->em->getFilters();
        $filter = $filters->isEnabled('tenant') ? $filters->getFilter('tenant') : $filters->enable('tenant');
        $filter->setParameter(TenantFilter::PARAMETER, $user->getClient()->getId()->toRfc4122(), 'string');
    }
}
