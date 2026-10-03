<?php

declare(strict_types=1);

namespace App\Api;

/**
 * An RFC 9457 problem raised by API code and rendered by App\Api\ProblemResponder.
 * `type` is "<SMARTHOST_PUBLIC_BASE_URL>/problems/<slug>". Details never reveal
 * whether another client's resource exists.
 */
final class ApiProblem extends \RuntimeException
{
    /**
     * @param list<array{pointer: string, message: string}> $errors
     * @param array<string, string>                         $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly array $errors = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($detail ?? $title);
    }

    public static function badRequest(string $detail, string $slug = 'bad-request'): self
    {
        return new self(400, $slug, 'Bad request', $detail);
    }

    public static function unauthorized(): self
    {
        return new self(401, 'unauthorized', 'Unauthorized', 'Missing, invalid or revoked API key.',
            headers: ['WWW-Authenticate' => 'Bearer']);
    }

    public static function forbidden(string $detail): self
    {
        return new self(403, 'forbidden', 'Forbidden', $detail);
    }

    public static function notFound(): self
    {
        return new self(404, 'not-found', 'Not found', 'The resource does not exist.');
    }

    public static function methodNotAllowed(): self
    {
        return new self(405, 'method-not-allowed', 'Method not allowed');
    }

    public static function conflict(string $slug, string $title, string $detail): self
    {
        return new self(409, $slug, $title, $detail);
    }

    public static function idempotencyInProgress(): self
    {
        return new self(409, 'idempotency-in-progress', 'Request in progress',
            'A request with the same Idempotency-Key is still being processed; retry later.');
    }

    public static function idempotencyKeyReused(): self
    {
        return new self(422, 'idempotency-key-reused', 'Idempotency-Key reused',
            'The Idempotency-Key was already used with a different request body.');
    }

    public static function payloadTooLarge(int $limit): self
    {
        return new self(413, 'payload-too-large', 'Payload too large', \sprintf('Request bodies are limited to %d bytes.', $limit));
    }

    /** @param list<array{pointer: string, message: string}> $errors */
    public static function unprocessable(string $slug, string $title, string $detail, array $errors = []): self
    {
        return new self(422, $slug, $title, $detail, $errors);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(429, 'rate-limited', 'Too many requests', 'Rate limit exceeded.',
            headers: ['Retry-After' => (string) max(0, $retryAfterSeconds)]);
    }

    public static function notImplemented(string $detail): self
    {
        return new self(501, 'not-implemented', 'Not implemented', $detail);
    }

    public static function internal(): self
    {
        return new self(500, 'internal-error', 'Internal server error', 'An unexpected error occurred.');
    }
}
