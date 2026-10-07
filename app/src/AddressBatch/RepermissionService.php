<?php

declare(strict_types=1);

namespace App\AddressBatch;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Enum\WebhookEventType;
use App\Enum\WebhookSubjectType;
use App\Util\Clock;
use App\Webhook\WebhookOutbox;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Recipients' answers to a re-permission message (specification 2.11).
 *
 * Each recipient of a batch send gets an unguessable token (256 bits, base64url); only its
 * SHA-256 is stored, with an expiry (APP_REPERMISSION_RESPONSE_DAYS). The public page
 * /p/{token} explains three distinct choices and acts only on POST (link scanners and
 * mail-client prefetches only GET):
 *
 *   confirm         stay subscribed to this list                 -> consent_state confirmed
 *   unsubscribe     leave this list (also RFC 8058 one-click)    -> consent_state unsubscribed
 *   global opt-out  no mail through this Smarthost at all        -> consent_state global_opt_out
 *                                                                   + a global recipient_global_opt_out suppression
 *
 * Answers are idempotent (the same answer twice changes nothing); confirm and unsubscribe
 * can replace each other; a global opt-out is final here (only an operator can lift the
 * suppression). Each change is audited without the address and is forwarded to the client
 * as repermission.responded: the subscription itself is the client application's state.
 * An ordinary list unsubscribe never becomes a Smarthost suppression (D-30).
 */
final class RepermissionService
{
    public const RESPONSES = ['confirm' => 'confirmed', 'unsubscribe' => 'unsubscribed', 'global_opt_out' => 'global_opt_out'];

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditLogger $audit,
        private readonly WebhookOutbox $outbox,
        #[Autowire('%app.repermission.response_days%')] private readonly int $responseDays,
        #[Autowire('%env(SMARTHOST_PUBLIC_BASE_URL)%')] private readonly string $publicBaseUrl,
    ) {
    }

    /** Issues a new token for an entry (the previous one stops working) and returns its URL. */
    public function issue(string $entryId): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->connection->executeStatement(<<<'SQL'
            UPDATE address_batch_entries SET response_token_hash = ?, response_token_expires_at = now() + make_interval(days => ?),
                   consent_state = CASE WHEN consent_state = 'unknown' THEN 'unconfirmed' ELSE consent_state END
             WHERE id = ? AND outcome = 'imported'
            SQL, [hash('sha256', $token), $this->responseDays, $entryId]);

        return $this->url($token);
    }

    public function url(string $token): string
    {
        return rtrim($this->publicBaseUrl, '/').'/p/'.$token;
    }

    public static function validToken(string $token): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{43}$/', $token);
    }

    /** @return array{entry_id: string, consent_state: string, batch_name: string, list_id: ?string, client_name: string, expired: bool}|null */
    public function resolve(string $token): ?array
    {
        if (!self::validToken($token)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT e.id::text AS entry_id, e.consent_state, b.name AS batch_name, b.list_id, c.company_name AS client_name,
                   e.response_token_expires_at < now() AS expired
              FROM address_batch_entries e JOIN address_batches b ON b.id = e.batch_id JOIN clients c ON c.id = b.client_id
             WHERE e.response_token_hash = ?
            SQL, [hash('sha256', $token)]);
        if (false === $row) {
            return null;
        }
        $row['expired'] = true === $row['expired'] || 't' === $row['expired'];

        return $row;
    }

    /**
     * @return array{state: string, changed: bool}|null null for an unknown or expired token
     */
    public function respond(string $token, string $action): ?array
    {
        $new = self::RESPONSES[$action] ?? throw new \InvalidArgumentException('Unknown answer.');
        if (!self::validToken($token)) {
            return null;
        }

        return $this->connection->transactional(function () use ($token, $new): ?array {
            $e = $this->connection->fetchAssociative(<<<'SQL'
                SELECT e.id::text AS id, e.consent_state, e.normalized_address, b.client_id::text AS client_id, b.id::text AS batch_id
                  FROM address_batch_entries e JOIN address_batches b ON b.id = e.batch_id
                 WHERE e.response_token_hash = ? AND e.response_token_expires_at > now() FOR UPDATE OF e
                SQL, [hash('sha256', $token)]);
            if (false === $e) {
                return null;
            }
            if ($e['consent_state'] === $new || 'global_opt_out' === $e['consent_state']) {
                return ['state' => $e['consent_state'], 'changed' => false];
            }
            $now = Clock::now()->format('Y-m-d H:i:s.uP');
            $this->connection->update('address_batch_entries', ['consent_state' => $new, 'consent_changed_at' => $now], ['id' => $e['id']]);
            if ('global_opt_out' === $new) {
                // D-30: a global suppression whose reporter is the client the message was sent for.
                $this->connection->executeStatement(<<<'SQL'
                    INSERT INTO suppressions (id, client_id, address_or_domain, scope_type, reason, created_at, source_client_id, external_reference)
                    VALUES (?, NULL, ?, 'address', 'recipient_global_opt_out', ?, ?, ?)
                    ON CONFLICT (source_client_id, address_or_domain) WHERE reason = 'recipient_global_opt_out' AND lifted_at IS NULL DO NOTHING
                    SQL, [Uuid::v7()->toRfc4122(), $e['normalized_address'], $now, $e['client_id'], 'repermission:'.$e['id']]);
            }
            $this->audit->record(AuditActor::system('repermission-page'), 'repermission.responded', 'address_batch_entry', $e['id'],
                ['batch_id' => $e['batch_id'], 'previous' => $e['consent_state'], 'response' => $new]);
            $this->outbox->record(Uuid::fromString($e['client_id']), WebhookEventType::RepermissionResponded,
                WebhookSubjectType::AddressBatchEntry, Uuid::fromString($e['id']));

            return ['state' => $new, 'changed' => true];
        });
    }
}
