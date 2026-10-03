<?php

declare(strict_types=1);

namespace App\Domain;

/** A management operation broke a domain rule (reported to the operator, never to the API). */
final class DomainRuleViolation extends \DomainException
{
}
