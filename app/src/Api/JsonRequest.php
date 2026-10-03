<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Limits;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads a JSON request body: size limit (413), media type and syntax (400),
 * then validation against an OpenAPI component schema (422 with JSON pointers).
 */
final class JsonRequest
{
    public function __construct(
        private readonly Limits $limits,
        private readonly OpenApiContract $contract,
    ) {
    }

    /**
     * @return array{data: array<string, mixed>, hash: string} the body as an associative
     *                                                         array and its canonical request hash
     */
    public function decode(Request $request, string $schema, bool $optional = false): array
    {
        $raw = $request->getContent();
        if (\strlen($raw) > $this->limits->maxRequestBytes) {
            throw ApiProblem::payloadTooLarge($this->limits->maxRequestBytes);
        }
        if ($optional && '' === trim($raw)) {
            return ['data' => [], 'hash' => RequestHasher::hash(new \stdClass())];
        }
        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]));
        if ('application/json' !== $type && !str_ends_with($type, '+json')) {
            throw ApiProblem::badRequest('The request body must be application/json.', 'unsupported-media-type');
        }
        try {
            $object = json_decode($raw, false, 64, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw ApiProblem::badRequest('The request body is not valid JSON.', 'malformed-json');
        }
        if (!$object instanceof \stdClass) {
            throw ApiProblem::unprocessable('validation-error', 'Validation failed', 'The request body must be a JSON object.',
                [['pointer' => '/', 'message' => 'Expected a JSON object.']]);
        }
        $errors = $this->contract->validate($object, '#/components/schemas/'.$schema);
        if ([] !== $errors) {
            throw ApiProblem::unprocessable('validation-error', 'Validation failed',
                'The request does not satisfy the API contract.', $errors);
        }

        return ['data' => json_decode($raw, true, 64, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING), 'hash' => RequestHasher::hash($object)];
    }
}
