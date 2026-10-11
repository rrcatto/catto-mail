<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The public front page (/, owner decision 2026-10-10), so that someone who types the
 * installation's address sees what the service is instead of a 404: sign-in, the API
 * documentation, and a word for recipients of its mail (templates/front.html.twig). Public,
 * static, no session, no cookies, no script.
 */
final class FrontPageController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    #[Route('/', name: 'front_page', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response($this->twig->render('front.html.twig'), 200, ['Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'none'; style-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'"]);
    }
}
