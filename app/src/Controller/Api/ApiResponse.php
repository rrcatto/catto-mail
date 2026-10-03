<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

final class ApiResponse
{
    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($body, $status, $headers + ['Cache-Control' => 'no-store']);
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);

        return $response;
    }
}
