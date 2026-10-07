<?php

declare(strict_types=1);

namespace App\Client;

use App\Enum\ClientStatus;

/**
 * The client lifecycle (specification 2.10, status-vocabulary client_status.transitions)
 * and the permission each transition needs in the dashboard and the console:
 *
 *   pending_approval -> active            approve                 PLATFORM.CLIENT.APPROVE
 *   pending_approval -> closed            reject                  PLATFORM.CLIENT.APPROVE
 *   active/throttled -> throttled/suspended  restrict (kill switch) PLATFORM.CLIENT.RESTRICT
 *   throttled        -> active            lift the throttle       PLATFORM.CLIENT.RESTRICT
 *   suspended        -> active/throttled  reactivate              PLATFORM.CLIENT.APPROVE
 *   any but closed   -> closed            close (final)           PLATFORM.CLIENT.APPROVE
 *
 * Nothing returns to pending_approval and closed is final, so an approval or a closure
 * cannot be undone silently. Restricting is a separate, narrower permission so that an
 * on-call operator can stop a client without being able to approve or reopen one.
 */
final class ClientStatusTransitions
{
    public const APPROVE = 'PLATFORM.CLIENT.APPROVE';
    public const RESTRICT = 'PLATFORM.CLIENT.RESTRICT';

    private const ALLOWED = [
        'pending_approval' => ['active' => self::APPROVE, 'closed' => self::APPROVE],
        'active' => ['throttled' => self::RESTRICT, 'suspended' => self::RESTRICT, 'closed' => self::APPROVE],
        'throttled' => ['active' => self::RESTRICT, 'suspended' => self::RESTRICT, 'closed' => self::APPROVE],
        'suspended' => ['active' => self::APPROVE, 'throttled' => self::APPROVE, 'closed' => self::APPROVE],
        'closed' => [],
    ];

    public static function allowed(ClientStatus $from, ClientStatus $to): bool
    {
        return isset(self::ALLOWED[$from->value][$to->value]);
    }

    /** The permission key the transition needs, or null when it is not allowed. */
    public static function permission(ClientStatus $from, ClientStatus $to): ?string
    {
        return self::ALLOWED[$from->value][$to->value] ?? null;
    }

    /** @return list<ClientStatus> */
    public static function targets(ClientStatus $from): array
    {
        return array_map(static fn (string $s): ClientStatus => ClientStatus::from($s), array_keys(self::ALLOWED[$from->value]));
    }

    /** @return array<string, list<string>> the vocabulary form (status-vocabulary.yaml client_status.transitions) */
    public static function asVocabulary(): array
    {
        $out = [];
        foreach (self::ALLOWED as $from => $targets) {
            if ([] !== $targets) {
                $out[$from] = array_keys($targets);
            }
        }

        return $out;
    }

    /** Words for the dashboard's action buttons. */
    public static function verb(ClientStatus $from, ClientStatus $to): string
    {
        return match (true) {
            ClientStatus::PendingApproval === $from && ClientStatus::Active === $to => 'Approve',
            ClientStatus::PendingApproval === $from && ClientStatus::Closed === $to => 'Reject and close',
            ClientStatus::Closed === $to => 'Close (final)',
            ClientStatus::Throttled === $to && ClientStatus::Suspended === $from => 'Reactivate throttled',
            ClientStatus::Throttled === $to => 'Throttle',
            ClientStatus::Suspended === $to => 'Suspend',
            ClientStatus::Suspended === $from => 'Reactivate',
            default => 'Lift throttle',
        };
    }
}
