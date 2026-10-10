<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Public client documentation (Phase 9): the normative OpenAPI contract and the
 * client integration guide, served as files from the image (copied from docs/api/ at
 * build time), plus a small index page (templates/docs/api.html.twig). Public, static,
 * no session, no cookies; the repository is the source of both documents.
 */
final class ApiDocumentationController
{
    private const FILES = [
        'openapi.v1.yaml' => 'application/yaml; charset=utf-8',
        'integration-guide.md' => 'text/markdown; charset=utf-8',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/contracts')]
        private readonly string $directory,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/docs/api', name: 'docs_api', methods: ['GET'])]
    public function index(): Response
    {
        // The stylesheet and its font are the application's own (no script, no image, nothing third-party).
        return new Response($this->twig->render('docs/api.html.twig'), 200, ['Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'self'; font-src 'self'; frame-ancestors 'none'"]);
    }

    #[Route('/docs/api/{file}', name: 'docs_api_file', methods: ['GET'], requirements: ['file' => 'openapi\.v1\.yaml|integration-guide\.md'])]
    public function file(string $file): Response
    {
        $path = $this->directory.'/'.$file;
        if (!isset(self::FILES[$file]) || !is_file($path) || 0 === filesize($path)) {
            throw new NotFoundHttpException('Not found.');
        }

        return new Response((string) file_get_contents($path), 200, ['Content-Type' => self::FILES[$file],
            'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'"]);
    }
}
