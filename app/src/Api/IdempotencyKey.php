<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

/** The required `Idempotency-Key` header (OpenAPI components.parameters.IdempotencyKey). */
final class IdempotencyKey
{
    private function __construct(public readonly string $value)
    {
    }

    public static function fromRequest(Request $request): self
    {
        $value = $request->headers->get('Idempotency-Key');
        if (null === $value || '' === $value) {
            throw ApiProblem::badRequest('The Idempotency-Key header is required.', 'missing-idempotency-key');
        }
        if (\strlen($value) < 8 || \strlen($value) > 255 || 1 !== preg_match('/^[\x21-\x7E]+$/', $value)) {
            throw ApiProblem::badRequest('The Idempotency-Key header must be 8-255 visible ASCII characters.', 'invalid-idempotency-key');
        }

        return new self($value);
    }
}
