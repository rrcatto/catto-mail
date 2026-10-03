<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Entity\Client;

/**
 * An entity whose table carries `client_id` directly (docs/schema/schema.md §3).
 * Child tables (addresses, recipients, messages, events) are scoped through their
 * parent and are covered by App\Tenant\TenantFilter's subqueries.
 */
interface TenantOwned
{
    public function getClient(): Client;
}
