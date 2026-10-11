<?php

declare(strict_types=1);

namespace App\Connect;

/**
 * The exchange of a connect code failed (App\Connect\ConnectService::exchange()).
 *
 * An invalid code is one answer for every reason - unknown, used, expired, revoked, or the
 * code verifier or redirect URI does not match - so the answer tells a guesser nothing. A
 * refusal means the code is good but the connection cannot be made now (the client is no
 * longer active, or a limit was reached since the approval); nothing was created, and the
 * code stays usable until it expires.
 */
final class ConnectFailure extends \RuntimeException
{
    private function __construct(public readonly bool $invalidCode, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidCode(): self
    {
        return new self(true, 'The code is not valid: it is unknown, expired or already used, or the code verifier or redirect URI does not match.');
    }

    public static function refused(string $detail): self
    {
        return new self(false, $detail);
    }
}
