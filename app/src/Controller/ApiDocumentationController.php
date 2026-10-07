<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public client documentation (Phase 9): the normative OpenAPI contract and the
 * client integration guide, served as files from the image (copied from docs/api/ at
 * build time), plus a small index page. Public, static, no session, no cookies; the
 * repository is the source of both documents.
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
    ) {
    }

    #[Route('/docs/api', name: 'docs_api', methods: ['GET'])]
    public function index(): Response
    {
        $html = <<<'HTML'
            <!DOCTYPE html>
            <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Catto Mail Smarthost API</title></head>
            <body>
            <h1>Catto Mail Smarthost API</h1>
            <ul>
            <li><a href="/docs/api/integration-guide.md">Client integration guide</a> (Markdown): authentication, idempotency, errors,
                pagination, validation and send jobs, webhooks, limits and quotas, polling and reconciliation.</li>
            <li><a href="/docs/api/openapi.v1.yaml">OpenAPI 3.1 contract</a> (normative): every endpoint, schema and status value.</li>
            </ul>
            <p>Delivery semantics: <em>remote_accepted</em> means the receiving mail server accepted responsibility for a message, not
            inbox placement; a recorded open is not a proven read; validation is not consent; an ordinary unsubscribe is not a global
            suppression.</p>
            </body></html>
            HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'"]);
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
