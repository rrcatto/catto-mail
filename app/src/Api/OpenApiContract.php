<?php

declare(strict_types=1);

namespace App\Api;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The normative OpenAPI contract (docs/api/openapi.v1.yaml, copied into the image)
 * as the single source of request validation: request bodies and parameters are
 * validated against its component schemas (OpenAPI 3.1 = JSON Schema 2020-12), so
 * the implementation cannot drift from the contract. Tests use the same class to
 * validate every response body.
 */
final class OpenApiContract
{
    private const BASE_URI = 'https://contract.smarthost.invalid/openapi.v1.json';
    private const MAX_ERRORS = 50;

    /** @var array<string, mixed>|null */
    private ?array $document = null;
    private ?Validator $validator = null;

    public function __construct(
        private readonly string $openApiContractPath,
        #[Autowire('%kernel.cache_dir%')]
        private readonly string $cacheDir,
    ) {
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        if (null === $this->document) {
            $cache = $this->cacheDir.'/openapi.v1.'.md5_file($this->openApiContractPath).'.php';
            if (is_file($cache)) {
                $this->document = require $cache;
            } else {
                $this->document = Yaml::parseFile($this->openApiContractPath);
                @mkdir(\dirname($cache), 0o775, true);
                $tmp = $cache.'.'.bin2hex(random_bytes(4));
                if (false !== @file_put_contents($tmp, '<?php return '.var_export($this->document, true).";\n")) {
                    @rename($tmp, $cache);
                }
            }
        }

        return $this->document;
    }

    /**
     * Validate decoded JSON (objects as stdClass) against a JSON pointer into the
     * contract, e.g. "#/components/schemas/RecipientBatchRequest".
     *
     * @return list<array{pointer: string, message: string}> empty when valid
     */
    public function validate(mixed $data, string $pointer): array
    {
        $result = $this->validator()->validate($data, self::BASE_URI.$pointer);
        if ($result->isValid()) {
            return [];
        }

        return $this->errors($result->error());
    }

    /** Pointer of the response body schema of an operation, for contract tests. */
    public function responseSchemaPointer(string $pathTemplate, string $method, int $status): ?string
    {
        $doc = $this->document();
        $response = $doc['paths'][$pathTemplate][strtolower($method)]['responses'][(string) $status] ?? null;
        if (isset($response['$ref'])) {
            $response = $this->resolve($response['$ref']);
        }
        $content = $response['content'] ?? null;
        if (null === $content) {
            return null;
        }
        $mediaType = isset($content['application/json']) ? 'application/json' : array_key_first($content);
        $schema = $content[$mediaType]['schema'] ?? null;

        return isset($schema['$ref']) ? $schema['$ref'] : null;
    }

    /** @return array<string, mixed> */
    public function resolve(string $ref): array
    {
        $node = $this->document();
        foreach (explode('/', ltrim(substr($ref, strpos($ref, '#') + 1), '/')) as $part) {
            $node = $node[str_replace(['~1', '~0'], ['/', '~'], $part)] ?? throw new \InvalidArgumentException("Unresolvable $ref");
        }

        return $node;
    }

    private function validator(): Validator
    {
        if (null === $this->validator) {
            $this->validator = new Validator(null, self::MAX_ERRORS, false);
            $this->validator->parser()->setDefaultDraftVersion('2020-12');
            $raw = json_decode(json_encode($this->document(), \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
            $this->validator->resolver()->registerRaw($raw, self::BASE_URI);
        }

        return $this->validator;
    }

    /** @return list<array{pointer: string, message: string}> */
    private function errors(ValidationError $error): array
    {
        $formatter = new ErrorFormatter();
        $out = [];
        $seen = [];
        $walk = function (ValidationError $e) use (&$walk, &$out, &$seen, $formatter): void {
            // Descend to the most specific errors; for oneOf/anyOf keep the wrapper if no leaf is clearer.
            if ([] !== $e->subErrors() && !\in_array($e->keyword(), ['anyOf', 'oneOf'], true)) {
                foreach ($e->subErrors() as $sub) {
                    $walk($sub);
                }

                return;
            }
            $pointer = $formatter->formatErrorKey($e);
            if ('additionalProperties' === $e->keyword()) {
                foreach ((array) ($e->args()['properties'] ?? []) as $property) {
                    $this->add($out, $seen, rtrim($pointer, '/').'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $property),
                        \sprintf('Unknown field "%s" is not accepted.', $property));
                }

                return;
            }
            if ('not' === $e->keyword()) {
                // e.g. `not: {required: [list_id]}`: name the field that must be absent.
                $forbidden = (array) ($e->schema()->info()->data()->not->required ?? []);
                foreach ($forbidden as $property) {
                    $this->add($out, $seen, rtrim($pointer, '/').'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $property),
                        \sprintf('Field "%s" is not accepted here.', $property));
                }
                if ([] !== $forbidden) {
                    return;
                }
            }
            if ('required' === $e->keyword()) {
                foreach ((array) ($e->args()['missing'] ?? []) as $property) {
                    $this->add($out, $seen, rtrim($pointer, '/').'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $property),
                        \sprintf('Field "%s" is required.', $property));
                }
                if ([] !== (array) ($e->args()['missing'] ?? [])) {
                    return;
                }
            }
            $message = match ($e->keyword()) {
                'anyOf' => 'Value does not satisfy any of the allowed forms (e.g. html_body and/or text_body is required).',
                default => $formatter->formatErrorMessage($e),
            };
            $this->add($out, $seen, '' === $pointer ? '/' : $pointer, $message);
        };
        $walk($error);

        return $out;
    }

    /**
     * @param list<array{pointer: string, message: string}> $out
     * @param array<string, true>                           $seen
     */
    private function add(array &$out, array &$seen, string $pointer, string $message): void
    {
        $k = $pointer."\0".$message;
        if (!isset($seen[$k]) && \count($out) < self::MAX_ERRORS) {
            $seen[$k] = true;
            $out[] = ['pointer' => $pointer, 'message' => $message];
        }
    }
}
