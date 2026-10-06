<?php

declare(strict_types=1);

namespace App\Webhook;

/** A webhook URL after validation, pinned to the address the request must connect to. */
final class WebhookTarget
{
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly string $ip,
    ) {
    }
}
