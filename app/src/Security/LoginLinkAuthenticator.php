<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * GET /dashboard/login/verify?token=… redeems a single-use emailed sign-in link
 * (LoginLinkService) and starts the dashboard session (Symfony migrates the
 * session id on login). HEAD requests (link scanners) never reach here: only GET
 * is supported, and the route answers HEAD without redeeming. Also the entry
 * point: an anonymous dashboard request is sent to the sign-in page, remembering
 * where it was going.
 */
final class LoginLinkAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    public const PATH = '/dashboard/login/verify';
    private const RESULT = '_smarthost_login_link';

    public function __construct(
        private readonly LoginLinkService $links,
        private readonly DashboardUserProvider $users,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('GET') && self::PATH === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        $result = $this->links->redeem($request->query->getString('token'));
        if (null === $result) {
            throw new CustomUserMessageAuthenticationException('This sign-in link is invalid, has expired or was already used. Request a new one.');
        }
        $request->attributes->set(self::RESULT, $result);
        $id = $result['user']->getId()->toRfc4122();

        return new SelfValidatingPassport(new UserBadge($result['user']->getEmail(), fn (): User => $this->users->loadById($id)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $result = $request->attributes->get(self::RESULT);
        $target = $result['return_path'] ?? null;
        $request->getSession()->getFlashBag()->add('success', 'You are signed in.');

        return new RedirectResponse($target ?? $this->urls->generate('dashboard_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urls->generate('dashboard_login'));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urls->generate('dashboard_login'));
    }
}
