<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\Installation;
use App\Util\Markdown;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The acceptable use policy (/acceptable-use-policy), linked from the footer of every page: the
 * page is built from docs/AUP.md, copied into the image at build time, so the repository is the
 * source of the text. The document names no product and no domain: its placeholders
 * ({{site_name}}, {{domain}}, {{organisation}}, {{contact}}) are filled from this installation's
 * configuration (App\Config\Installation), after rendering and escaped, so a configured value
 * is always shown as it is written. Public, static, no session, no cookies, no script.
 */
final class PolicyController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/contracts/AUP.md')]
        private readonly string $file,
        private readonly Environment $twig,
        private readonly Installation $installation,
    ) {
    }

    #[Route('/acceptable-use-policy', name: 'acceptable_use_policy', methods: ['GET'])]
    public function __invoke(): Response
    {
        $markdown = is_file($this->file) ? (string) file_get_contents($this->file) : '';
        if ('' === trim($markdown)) {
            throw new NotFoundHttpException('Not found.');
        }

        $names = $this->installation->placeholders();
        $escaped = array_map(static fn (string $value): string => htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'), $names);

        return new Response($this->twig->render('policy.html.twig', ['title' => strtr(Markdown::title($markdown) ?? 'Acceptable Use Policy', $names),
            'policy' => strtr(Markdown::toHtml($markdown), $escaped)]), 200, ['Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'none'; style-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'"]);
    }
}
