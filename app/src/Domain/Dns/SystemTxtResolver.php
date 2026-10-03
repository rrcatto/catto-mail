<?php

declare(strict_types=1);

namespace App\Domain\Dns;

/** TXT lookups through the system resolver (PHP dns_get_record). */
final class SystemTxtResolver implements TxtResolver
{
    public function lookup(string $name): array
    {
        $records = @dns_get_record($name, \DNS_TXT);
        if (false === $records) {
            throw new DnsLookupFailed(\sprintf('TXT lookup for %s failed.', $name));
        }
        $out = [];
        foreach ($records as $record) {
            if (isset($record['entries']) && \is_array($record['entries'])) {
                $out[] = implode('', $record['entries']);
            } elseif (isset($record['txt'])) {
                $out[] = (string) $record['txt'];
            }
        }

        return $out;
    }
}
