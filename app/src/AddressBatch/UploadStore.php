<?php

declare(strict_types=1);

namespace App\AddressBatch;

use App\Domain\DomainRuleViolation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps an uploaded address file between the preview and the import (specification
 * 2.11): in the application's private var/ directory, mode 0600, bound to the
 * administrator who uploaded it, removed on import and after an hour at the latest.
 * The file never leaves the application container and is never served.
 */
final class UploadStore
{
    private const TTL_SECONDS = 3600;

    public function __construct(#[Autowire('%kernel.project_dir%/var/address-uploads')] private readonly string $dir)
    {
    }

    public function put(string $bytes, string $filename, string $userId): string
    {
        $this->prune();
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Cannot create the upload directory.');
        }
        $id = bin2hex(random_bytes(16));
        $old = umask(0077);
        try {
            file_put_contents("$this->dir/$id.bin", $bytes);
            file_put_contents("$this->dir/$id.json", json_encode(['filename' => $filename, 'user' => $userId, 'at' => time()], \JSON_THROW_ON_ERROR));
        } finally {
            umask($old);
        }

        return $id;
    }

    /** @return array{bytes: string, filename: string} */
    public function get(string $id, string $userId): array
    {
        if (1 !== preg_match('/^[0-9a-f]{32}$/', $id) || !is_file("$this->dir/$id.json")) {
            throw new DomainRuleViolation('The upload has expired; upload the file again.');
        }
        $meta = json_decode((string) file_get_contents("$this->dir/$id.json"), true);
        if (!\is_array($meta) || ($meta['user'] ?? null) !== $userId || time() - (int) ($meta['at'] ?? 0) > self::TTL_SECONDS) {
            throw new DomainRuleViolation('The upload has expired; upload the file again.');
        }

        return ['bytes' => (string) file_get_contents("$this->dir/$id.bin"), 'filename' => (string) $meta['filename']];
    }

    public function delete(string $id): void
    {
        if (1 === preg_match('/^[0-9a-f]{32}$/', $id)) {
            @unlink("$this->dir/$id.bin");
            @unlink("$this->dir/$id.json");
        }
    }

    private function prune(): void
    {
        foreach (glob("$this->dir/*.json") ?: [] as $meta) {
            if (time() - (int) @filemtime($meta) > self::TTL_SECONDS) {
                $this->delete(basename($meta, '.json'));
            }
        }
    }
}
