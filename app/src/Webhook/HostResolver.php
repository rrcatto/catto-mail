<?php

declare(strict_types=1);

namespace App\Webhook;

/** DNS boundary of the webhook worker (replaced by a stub in tests). */
interface HostResolver
{
    /**
     * @return list<string> the A and AAAA addresses of $host (empty when it has none)
     *
     * @throws \RuntimeException on a temporary resolution failure
     */
    public function resolve(string $host): array;
}
