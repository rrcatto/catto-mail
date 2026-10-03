<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ApiProblem;
use App\Api\ProblemResponder;
use App\Entity\ApiKey;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * `Authorization: Bearer <api key>` for /v1 (OpenAPI securitySchemes.apiKey).
 *
 * The key is looked up by its SHA-256 hash; revoked keys and keys of closed
 * clients are rejected with the same 401 as unknown keys (no oracle). Failed
 * attempts are rate limited per client IP. last_used_at is maintained with
 * one-minute granularity so that busy keys do not write on every request.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private const LAST_USED_GRANULARITY_SECONDS = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly ProblemResponder $problems,
        #[Target('api_auth_failure.limiter')]
        private readonly RateLimiterFactoryInterface $failureLimiter,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return true; // every /v1 request must authenticate
    }

    public function authenticate(Request $request): Passport
    {
        // Only failures consume from this limiter (onAuthenticationFailure); once the
        // window is exhausted every attempt from that address is refused.
        $limit = $this->failureLimiter->create($request->getClientIp() ?? 'unknown');
        if (0 === $limit->consume(0)->getRemainingTokens()) {
            throw new CustomUserMessageAuthenticationException('rate_limited');
        }
        $header = (string) $request->headers->get('Authorization', '');
        if (1 !== preg_match('/^Bearer ([^\s]+)$/', $header, $m) || 1 !== preg_match(ApiKeyManager::RAW_KEY_PATTERN, $m[1])) {
            throw new CustomUserMessageAuthenticationException('invalid');
        }
        $hash = ApiKeyManager::hash($m[1]);

        return new SelfValidatingPassport(new UserBadge($hash, function (string $hash): ApiClientUser {
            $key = $this->em->getRepository(ApiKey::class)->findOneBy(['keyHash' => $hash]);
            if (null === $key || $key->isRevoked() || $key->getClient()->isClosed()) {
                throw new CustomUserMessageAuthenticationException('invalid');
            }
            $this->connection->executeStatement(
                'UPDATE api_keys SET last_used_at = now() WHERE id = :id AND (last_used_at IS NULL OR last_used_at < now() - make_interval(secs => :g))',
                ['id' => $key->getId()->toRfc4122(), 'g' => self::LAST_USED_GRANULARITY_SECONDS]);

            return new ApiClientUser($key);
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $limit = $this->failureLimiter->create($request->getClientIp() ?? 'unknown')->consume(1);
        if ('rate_limited' === $exception->getMessageKey() || !$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return $this->problems->respond(ApiProblem::rateLimited($retry), $request);
        }

        return $this->problems->respond(ApiProblem::unauthorized(), $request);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->problems->respond(ApiProblem::unauthorized(), $request);
    }
}
