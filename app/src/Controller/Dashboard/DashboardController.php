<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Dashboard\ClientAccess;
use App\Security\LoginLinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Dashboard entry points (D-12, passwordless since specification 2.7). The user
 * enters an email address and receives a single-use sign-in link
 * (LoginLinkService); the link is redeemed by LoginLinkAuthenticator. There are no
 * passwords and no self-service registration: an operator creates users in the
 * dashboard, and APP_ADMIN_EMAIL can always sign in. Sign-out is a POST with a
 * CSRF token (the firewall's logout listener handles it).
 */
#[Route('/dashboard')]
final class DashboardController extends AbstractController
{
    use TargetPathTrait;

    public const CSRF_LOGIN = 'login-link';

    #[Route('/login', name: 'dashboard_login', methods: ['GET'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('dashboard_home');
        }
        $error = $authenticationUtils->getLastAuthenticationError();

        return $this->render('dashboard/login.html.twig', ['error' => $error?->getMessageKey()]);
    }

    /** Always answers the same way, whether or not a link was sent (no account enumeration). */
    #[Route('/login', name: 'dashboard_login_request', methods: ['POST'])]
    public function requestLink(Request $request, LoginLinkService $links): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_LOGIN, $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', 'The form expired. Please try again.');

            return $this->redirectToRoute('dashboard_login');
        }
        // Symfony remembers the page an anonymous visitor asked for as an absolute URL;
        // only its path (and query) is kept, and LoginLinkService accepts /dashboard paths only.
        $target = $this->getTargetPath($request->getSession(), 'dashboard');
        $returnPath = null;
        if (null !== $target) {
            $parts = parse_url($target);
            $returnPath = ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }
        try {
            $links->request($request->request->getString('email'), $request->getClientIp(), '' === $returnPath ? null : $returnPath);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('dashboard_login');
        }

        return $this->redirectToRoute('dashboard_login_sent');
    }

    #[Route('/login/sent', name: 'dashboard_login_sent', methods: ['GET'])]
    public function sent(): Response
    {
        return $this->render('dashboard/login_sent.html.twig', ['minutes' => intdiv((int) $this->getParameter('app.login_link_ttl_seconds'), 60)]);
    }

    /**
     * The emailed link. GET is redeemed by LoginLinkAuthenticator before this
     * controller runs; HEAD (mail-security scanners) is answered without redeeming.
     */
    #[Route('/login/verify', name: 'dashboard_login_verify', methods: ['GET', 'HEAD'])]
    public function verify(Request $request): Response
    {
        if ($request->isMethod('HEAD')) {
            return new Response('', Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
        }

        return $this->redirectToRoute(null === $this->getUser() ? 'dashboard_login' : 'dashboard_home');
    }

    /**
     * Platform users land on the system overview (or their first platform page);
     * a user with one client lands on it; anyone else gets the chooser.
     */
    #[Route('', name: 'dashboard_home', methods: ['GET'])]
    public function home(ClientAccess $access): Response
    {
        $user = $access->user();
        $memberships = $access->memberships();
        if ($user->hasPermission('PLATFORM.OVERVIEW.VIEW') && [] === $memberships) {
            return $this->redirectToRoute('dashboard_operator_overview');
        }
        if (!$user->isPlatformUser() && 1 === \count($memberships)) {
            return $this->redirectToRoute('dashboard_client_overview', ['clientId' => $memberships[0]->getClient()->getId()->toRfc4122()]);
        }

        return $this->render('dashboard/home.html.twig', ['memberships' => $memberships, 'section' => 'home']);
    }

    #[Route('/logout', name: 'dashboard_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the dashboard firewall.');
    }
}
