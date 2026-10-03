<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Limits;
use App\Security\ApiClientUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Request-level API limits:
 *  - body size (APP_API_MAX_REQUEST_BYTES, 413) before routing or authentication;
 *  - per-API-key rate limit (APP_API_RATE_LIMIT_PER_MINUTE, 429 + Retry-After)
 *    right after the firewall authenticated the key.
 */
final class RequestLimitsSubscriber
{
    public function __construct(
        private readonly Limits $limits,
        private readonly Security $security,
        #[Target('api_key.limiter')]
        private readonly RateLimiterFactoryInterface $apiKeyLimiter,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
    public function limitBodySize(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !ApiPath::matches($request->getPathInfo())) {
            return;
        }
        $declared = $request->headers->get('Content-Length');
        if (null !== $declared && (int) $declared > $this->limits->maxRequestBytes) {
            throw ApiProblem::payloadTooLarge($this->limits->maxRequestBytes);
        }
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
    public function rateLimit(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !ApiPath::matches($event->getRequest()->getPathInfo())) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof ApiClientUser) {
            return;
        }
        $limit = $this->apiKeyLimiter->create($user->getApiKey()->getId()->toRfc4122())->consume(1);
        if (!$limit->isAccepted()) {
            throw ApiProblem::rateLimited(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
    }
}
