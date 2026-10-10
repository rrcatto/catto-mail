<?php

declare(strict_types=1);

namespace App\System;

/**
 * What the last configuration render put in force (infra/.generated/settings.json, mounted
 * read-only into the application container): each changeable setting's value from infra/.env
 * (or built in), the dashboard overrides it applied, those it ignored and why.
 */
final class AppliedSettings
{
    public function __construct(private readonly string $path)
    {
    }

    /** @return array{rendered_at: string, config: array<string, string>, applied: array<string, string>, ignored: array<string, string>, errors: list<string>}|null */
    public function state(): ?array
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            return null;
        }
        try {
            $doc = json_decode((string) file_get_contents($this->path), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($doc) ? [
            'rendered_at' => (string) ($doc['rendered_at'] ?? ''),
            'config' => array_map('strval', (array) ($doc['config'] ?? [])),
            'applied' => array_map('strval', (array) ($doc['applied'] ?? [])),
            'ignored' => array_map('strval', (array) ($doc['ignored'] ?? [])),
            'errors' => array_values(array_map('strval', (array) ($doc['errors'] ?? []))),
        ] : null;
    }
}
