<?php

declare(strict_types=1);

namespace App\Api;

use App\Client\ClientLimitPolicy;
use App\Config\Limits;
use App\Security\ApiClientUser;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Request-level API limits:
 *  - body size (APP_API_MAX_REQUEST_BYTES, 413) before routing or authentication;
 *  - per-API-key rate limit (APP_API_RATE_LIMIT_PER_MINUTE, 429 + Retry-After)
 *    right after the firewall authenticated the key;
 *  - Phase 9: per-client rate limit over all of the client's keys together, at the
 *    client's effective limit (App\Client\ClientLimitPolicy: its own limit, never above
 *    APP_API_RATE_LIMIT_PER_MINUTE, and APP_THROTTLED_CLIENT_API_RATE_PER_MINUTE while the
 *    client is throttled).
 *
 * The rate limiters keep their windows in the application cache of the web container
 * (cache.rate_limiter): they protect the request path of that container. Quotas that
 * must hold across processes and restarts (jobs, addresses, recipients) are durable
 * PostgreSQL counters instead (App\Client\QuotaEnforcer).
 */
final class RequestLimitsSubscriber
{
    public function __construct(
        private readonly Limits $limits,
        private readonly Security $security,
        #[Target('api_key.limiter')]
        private readonly RateLimiterFactoryInterface $apiKeyLimiter,
        private readonly ClientLimitPolicy $clientLimits,
        #[Autowire(service: 'cache.rate_limiter')]
        private readonly CacheItemPoolInterface $limiterCache,
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
        $client = $user->getClient();
        $rate = $this->clientLimits->apiRequestsPerMinute($client);
        // One window per client and rate: a changed limit (or throttling) starts a fresh window.
        $factory = new RateLimiterFactory(['id' => 'api_client_'.$rate, 'policy' => 'sliding_window', 'limit' => $rate,
            'interval' => '1 minute'], new CacheStorage($this->limiterCache));
        $limit = $factory->create($client->getId()->toRfc4122())->consume(1);
        if (!$limit->isAccepted()) {
            throw ApiProblem::rateLimited(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
    }
}
