<?php

declare(strict_types=1);

namespace App\Domain\Dns;

/** DNS TXT lookup boundary (tests replace it; automated tests never use public DNS). */
interface TxtResolver
{
    /**
     * @return list<string> the TXT records of $name, each with its character-strings concatenated;
     *                      empty when the name has no TXT records
     *
     * @throws DnsLookupFailed when the lookup itself fails (timeout, SERVFAIL, no resolver)
     */
    public function lookup(string $name): array;
}
