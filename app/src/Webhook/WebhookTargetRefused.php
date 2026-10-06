<?php

declare(strict_types=1);

namespace App\Webhook;

/** The destination is not allowed (SSRF policy). Permanent: the delivery is not retried. */
final class WebhookTargetRefused extends \RuntimeException
{
}
