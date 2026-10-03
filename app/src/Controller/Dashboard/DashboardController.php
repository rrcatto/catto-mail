<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Minimal dashboard authentication foundation (D-12): form login for users with a
 * session, CSRF-protected. The client and operator dashboards are later phases;
 * this only proves the separate login path. API keys are never accepted here.
 */
#[Route('/dashboard')]
final class DashboardController
{
    #[Route('/login', name: 'dashboard_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $utils, CsrfTokenManagerInterface $csrf): Response
    {
        $error = $utils->getLastAuthenticationError() ? '<p role="alert">Sign-in failed.</p>' : '';
        $email = htmlspecialchars($utils->getLastUsername(), \ENT_QUOTES);
        $token = htmlspecialchars($csrf->getToken('authenticate')->getValue(), \ENT_QUOTES);

        return new Response(<<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8"><title>Smarthost sign in</title></head>
            <body><h1>Smarthost</h1>$error
            <form method="post" action="/dashboard/login">
              <label>Email <input type="email" name="email" value="$email" autocomplete="username" required></label>
              <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
              <input type="hidden" name="_csrf_token" value="$token">
              <button type="submit">Sign in</button>
            </form></body></html>
            HTML, 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('', name: 'dashboard_home', methods: ['GET'])]
    public function home(Security $security): Response
    {
        $user = $security->getUser();
        $name = $user instanceof User ? htmlspecialchars($user->getEmail(), \ENT_QUOTES) : '';
        $role = $user instanceof User && $user->isOperator() ? ' (operator)' : '';

        return new Response("<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>Smarthost</title></head>"
            ."<body><p>Signed in as $name$role.</p><p><a href=\"/dashboard/logout\">Sign out</a></p></body></html>",
            200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/logout', name: 'dashboard_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the dashboard firewall.');
    }
}
