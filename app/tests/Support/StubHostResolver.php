<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Webhook\HostResolver;

/** Deterministic DNS for webhook tests: names map to fixed addresses; unknown names fail temporarily. */
final class StubHostResolver implements HostResolver
{
    /** @var array<string, list<string>> */
    public static array $answers = [];

    public function resolve(string $host): array
    {
        return self::$answers[strtolower($host)] ?? throw new \RuntimeException("stub DNS: no answer for $host");
    }
}
