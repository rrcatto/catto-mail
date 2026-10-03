<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/** Records users.last_login_at on a successful dashboard login. */
#[AsEventListener]
final class DashboardLoginSubscriber
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ('dashboard' === $event->getFirewallName() && $user instanceof User) {
            $user->recordLogin();
            $this->em->flush();
        }
    }
}
