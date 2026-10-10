<?php

declare(strict_types=1);

namespace App\Controller;

use App\AddressBatch\RepermissionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public re-permission page (specification 2.11): /p/{token}, linked from every
 * re-permission message. No sign-in, no session, no cookie, no script; the address is never
 * in the URL and never shown. GET only displays the three choices (link scanners and mail
 * clients prefetch with GET); the answer is a POST of the chosen button. A mailbox
 * provider's RFC 8058 one-click unsubscribe (POST List-Unsubscribe=One-Click) unsubscribes
 * from this list only. Unknown and expired tokens get one neutral page.
 */
final class RepermissionController extends AbstractController
{
    private const HEADERS = [
        'Cache-Control' => 'no-store, private, max-age=0',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "default-src 'none'; style-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
    ];

    public function __construct(
        private readonly RepermissionService $repermission,
        private readonly RateLimiterFactoryInterface $trackingLimiter,
    ) {
    }

    #[Route('/p/{token}', name: 'repermission_page', requirements: ['token' => '[A-Za-z0-9_-]{1,64}'], methods: ['GET'])]
    public function page(string $token, Request $request): Response
    {
        if ('seed-test' === $token) {
            return $this->view('seed', ['choice' => $request->query->getString('choice')]);
        }
        $entry = $this->repermission->resolve($token);
        if (null === $entry || $entry['expired']) {
            return $this->view('unknown', [], 404);
        }
        $choice = $request->query->getString('choice');

        return $this->view('choose', ['entry' => $entry, 'token' => $token,
            'choice' => \in_array($choice, ['confirm', 'unsubscribe', 'global_opt_out'], true) ? $choice : null]);
    }

    #[Route('/p/{token}', name: 'repermission_answer', requirements: ['token' => '[A-Za-z0-9_-]{1,64}'], methods: ['POST'])]
    public function answer(string $token, Request $request): Response
    {
        if (!$this->trackingLimiter->create('repermission|'.$request->getClientIp())->consume()->isAccepted()) {
            return $this->view('busy', [], 429);
        }
        if ('seed-test' === $token) {
            return $this->view('seed', ['choice' => null]);
        }
        $oneClick = 'One-Click' === $request->request->getString('List-Unsubscribe');
        $action = $oneClick ? 'unsubscribe' : $request->request->getString('answer');
        if (!isset(RepermissionService::RESPONSES[$action])) {
            return $this->redirectToRoute('repermission_page', ['token' => $token], 303);
        }
        $result = $this->repermission->respond($token, $action);
        if (null === $result) {
            return $this->view('unknown', [], 404);
        }
        if ($oneClick) {
            return new Response('', 200, self::HEADERS);
        }
        $entry = $this->repermission->resolve($token);

        return $this->view('done', ['entry' => $entry, 'token' => $token, 'state' => $result['state'], 'changed' => $result['changed']]);
    }

    /** @param array<string, mixed> $vars */
    private function view(string $view, array $vars, int $status = 200): Response
    {
        $response = $this->render('repermission/page.html.twig', ['view' => $view] + $vars);
        $response->setStatusCode($status);
        $response->headers->add(self::HEADERS);

        return $response;
    }
}
