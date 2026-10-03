<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Dns\DnsLookupFailed;
use App\Domain\Dns\TxtResolver;

/** Deterministic DNS for tests: records and failures are set per name. */
final class StubTxtResolver implements TxtResolver
{
    /** @var array<string, list<string>|\Throwable> */
    private array $answers = [];

    /** @param list<string> $records */
    public function set(string $name, array $records): void
    {
        $this->answers[strtolower($name)] = $records;
    }

    public function fail(string $name): void
    {
        $this->answers[strtolower($name)] = new DnsLookupFailed("TXT lookup for $name failed (stub SERVFAIL).");
    }

    public function lookup(string $name): array
    {
        $answer = $this->answers[strtolower($name)] ?? [];
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }
}
