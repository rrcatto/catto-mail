<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Renders App\Api\ApiProblem as `application/problem+json` (OpenAPI Problem schema). */
final class ProblemResponder
{
    public function __construct(private readonly string $publicBaseUrl)
    {
    }

    public function respond(ApiProblem $problem, ?Request $request = null): JsonResponse
    {
        $body = [
            'type' => rtrim($this->publicBaseUrl, '/').'/problems/'.$problem->slug,
            'title' => $problem->title,
            'status' => $problem->status,
        ];
        if (null !== $problem->detail) {
            $body['detail'] = $problem->detail;
        }
        if (null !== $request) {
            $body['instance'] = $request->getPathInfo();
        }
        if ([] !== $problem->errors) {
            $body['errors'] = $problem->errors;
        }
        foreach ($problem->extensions as $member => $value) {
            $body[$member] ??= $value;
        }
        $response = new JsonResponse($body, $problem->status, $problem->headers + ['Cache-Control' => 'no-store']);
        $response->headers->set('Content-Type', 'application/problem+json');
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return $response;
    }
}
