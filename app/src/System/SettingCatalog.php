<?php

declare(strict_types=1);

namespace App\System;

/**
 * The settings an administrator may change in the dashboard (docs/contracts/settings.json,
 * copied into the image): each one's group, label, kind and allowed values. The renderer
 * (infra/lib/smarthost_render.py, override_error) applies the same rules when it merges the
 * overrides over infra/.env, so a value accepted here is applied there.
 */
final class SettingCatalog
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $entries = null;

    public function __construct(private readonly string $path)
    {
    }

    /** @return array<string, array<string, mixed>> name => entry, in catalogue order */
    public function all(): array
    {
        if (null === $this->entries) {
            $doc = json_decode((string) file_get_contents($this->path), true, 16, \JSON_THROW_ON_ERROR);
            $this->entries = [];
            foreach ($doc['settings'] as $entry) {
                $this->entries[(string) $entry['name']] = $entry;
            }
        }

        return $this->entries;
    }

    /** @return array<string, array<string, array<string, mixed>>> group => name => entry, for this environment */
    public function grouped(string $environment): array
    {
        $groups = [];
        foreach ($this->all() as $name => $entry) {
            if (!($entry['production_only'] ?? false) || 'production' === $environment) {
                $groups[(string) $entry['group']][$name] = $entry;
            }
        }

        return $groups;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /** Why the value is not acceptable for the setting, or null. */
    public function error(string $name, string $value, string $environment): ?string
    {
        $entry = $this->all()[$name] ?? null;
        if (null === $entry) {
            return 'This setting cannot be changed in the dashboard.';
        }
        if (($entry['production_only'] ?? false) && 'production' !== $environment) {
            return 'This setting applies to production only.';
        }
        if ('' === $value && ($entry['empty'] ?? false)) {
            return null;
        }

        return match ($entry['kind']) {
            'integer', 'decimal' => 1 !== preg_match('integer' === $entry['kind'] ? '/^-?\d{1,12}$/' : '/^-?\d{1,12}(\.\d{1,6})?$/', $value)
                ? ('integer' === $entry['kind'] ? 'Give a whole number.' : 'Give a number (a dot for decimals).')
                : ((float) $value < $entry['min'] || (float) $value > $entry['max'] ? \sprintf('Give a value from %s to %s.', $entry['min'], $entry['max']) : null),
            'choice' => \in_array($value, $entry['choices'], true) ? null : 'Choose one of: '.implode(', ', $entry['choices']).'.',
            'timezone' => 1 === preg_match('~^[A-Za-z][A-Za-z0-9_+-]*(/[A-Za-z0-9_+-]+)*$~', $value)
                && \in_array($value, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)
                ? null : 'Give an IANA time zone name such as Africa/Johannesburg.',
            'text' => 1 === preg_match('~'.str_replace('~', '\~', (string) $entry['pattern']).'~', $value) ? null : 'This value has characters or a form the setting does not accept.',
            default => 'Unknown kind of setting.',
        };
    }
}
