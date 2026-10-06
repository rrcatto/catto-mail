<?php

declare(strict_types=1);

namespace App\Webhook;

final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips = [];
        $v4 = @gethostbynamel($host);
        foreach (false === $v4 ? [] : $v4 as $ip) {
            $ips[] = $ip;
        }
        $records = @dns_get_record($host, \DNS_AAAA);
        foreach (false === $records ? [] : $records as $r) {
            if (isset($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if ([] === $ips && false === $v4 && false === $records) {
            throw new \RuntimeException("DNS resolution of $host failed.");
        }

        return array_values(array_unique($ips));
    }
}
