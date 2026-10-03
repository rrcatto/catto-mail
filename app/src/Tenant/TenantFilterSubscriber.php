<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Security\ApiClientUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Turns on App\Tenant\TenantFilter for the authenticated API client of this
 * request, and turns it off at the start of every main request so that a
 * long-lived kernel (worker runtimes, tests) never carries one client's filter
 * into the next request's authentication.
 */
final class TenantFilterSubscriber
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
    public function reset(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->em->getFilters()->isEnabled('tenant')) {
            $this->em->getFilters()->disable('tenant');
        }
    }

    #[AsEventListener]
    public function onLogin(LoginSuccessEvent $event): void
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
