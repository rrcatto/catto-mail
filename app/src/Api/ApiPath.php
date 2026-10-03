<?php

declare(strict_types=1);

namespace App\Api;

final class ApiPath
{
    public static function matches(string $path): bool
    {
        return '/v1' === $path || str_starts_with($path, '/v1/');
    }
}
