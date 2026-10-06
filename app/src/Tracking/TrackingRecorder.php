<?php

declare(strict_types=1);

namespace App\Tracking;

use App\Util\Clock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Records engagement from the public tracking endpoints (spec
 * message_tracking.engagement_tracking; Phase 6).
 *
 * Tokens are the opaque 192-bit random values Go stores in messages.tracking_token
 * (never signed, never derived from an address). A token is looked up, never
 * trusted: it must be well formed, exist, belong to a message that was handed to
 * Postfix, be within APP_RETENTION_TRACKING_DAYS (when set), and the message's job
 * must have the tracking kind enabled. Anything else is treated exactly like an
 * unknown token, so a request never reveals whether a token or message exists.
 *
 * Events are append-only `open_recorded` / `click_recorded` rows with
 * event_source `tracking_endpoint` and no source key (repeats are legitimate
 * distinct events, D-06). To keep automated repetition (prefetchers, scanners,
 * reloads) from growing the table without bound, a request is NOT recorded when
 * (recording rule, spec 2.6):
 *   - an open of the same message was recorded less than OPEN_INTERVAL seconds ago;
 *   - a click of the same message and link was recorded less than CLICK_INTERVAL seconds ago;
 *   - the message already has MAX_EVENTS_PER_MESSAGE events of that type;
 *   - the client address exceeded the tracking rate limit (decided by the caller).
 * The pixel and the redirect are still served; only the database write is skipped.
 * A per-message transaction-scoped advisory lock makes the check-and-insert atomic.
 * Nothing about the requester (IP address, user agent) is stored.
 */
final class TrackingRecorder
{
    public const OPEN_INTERVAL_SECONDS = 60;
    public const CLICK_INTERVAL_SECONDS = 10;
    public const MAX_EVENTS_PER_MESSAGE = 1000;
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{32,64}$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $trackingRetentionDays,
    ) {
    }

    public static function isWellFormed(string $token): bool
    {
        return 1 === preg_match(self::TOKEN_PATTERN, $token);
    }

    /** Records an open if the token is valid and eligible; true when an event was written. */
    public function recordOpen(string $token, bool $mayWrite): bool
    {
        $m = $this->resolve($token);
        if (null === $m || !$m['track_opens'] || !$mayWrite) {
            return false;
        }

        return $this->append($m['id'], 'open_recorded', self::OPEN_INTERVAL_SECONDS, null);
    }

    /**
     * The stored redirect target of a tracked link, or null (unknown token,
     * ineligible message, unknown link index). The target only ever comes from
     * message_links, written by Go for that message; nothing in the request can
     * influence it. The click is recorded before the target is returned.
     */
    public function resolveClick(string $token, int $linkIndex, bool $mayWrite): ?string
    {
        $m = $this->resolve($token);
        if (null === $m || !$m['track_clicks'] || $linkIndex < 1) {
            return null;
        }
        $target = $this->connection->fetchOne('SELECT target_url FROM message_links WHERE message_id = ? AND link_index = ?',
            [$m['id'], $linkIndex]);
        if (!\is_string($target) || !self::isSafeTarget($target)) {
            return null;
        }
        if ($mayWrite) {
            $this->append($m['id'], 'click_recorded', self::CLICK_INTERVAL_SECONDS, $linkIndex);
        }

        return $target;
    }

    /** Defence in depth on top of the database CHECK: an absolute http(s) URL without whitespace or control characters. */
    public static function isSafeTarget(string $url): bool
    {
        if (1 === preg_match('/[\x00-\x20\x7F]/', $url)) {
            return false;
        }
        $parts = parse_url($url);

        return \is_array($parts) && isset($parts['scheme'], $parts['host']) && \in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && '' !== $parts['host'];
    }

    /** @return array{id: string, track_opens: bool, track_clicks: bool}|null */
    private function resolve(string $token): ?array
    {
        if (!self::isWellFormed($token)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT m.id::text AS id, j.track_opens, j.track_clicks, m.created_at
              FROM messages m JOIN send_jobs j ON j.id = m.send_job_id
             WHERE m.tracking_token = ? AND m.postfix_queue_id IS NOT NULL
            SQL, [$token]);
        if (false === $row) {
            return null;
        }
        $days = trim($this->trackingRetentionDays);
        if ('' !== $days && ctype_digit($days)
            && new \DateTimeImmutable((string) $row['created_at']) < Clock::now()->modify('-'.(int) $days.' days')) {
            return null; // expired (APP_RETENTION_TRACKING_DAYS): indistinguishable from unknown
        }

        return ['id' => $row['id'], 'track_opens' => (bool) $row['track_opens'], 'track_clicks' => (bool) $row['track_clicks']];
    }

    private function append(string $messageId, string $type, int $intervalSeconds, ?int $linkIndex): bool
    {
        return $this->connection->transactional(function (Connection $c) use ($messageId, $type, $intervalSeconds, $linkIndex): bool {
            $c->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['smarthost-tracking|'.$messageId.'|'.$type]);
            $stats = $c->fetchAssociative(<<<'SQL'
                SELECT count(*) AS n,
                       bool_or(occurred_at > now() - make_interval(secs => ?) AND (?::int IS NULL OR (metadata_json->>'link_index')::int = ?::int)) AS recent
                  FROM message_events WHERE message_id = ? AND event_type = ?
                SQL, [$intervalSeconds, $linkIndex, $linkIndex, $messageId, $type]);
            if ((int) $stats['n'] >= self::MAX_EVENTS_PER_MESSAGE || true === $stats['recent']) {
                return false;
            }
            $c->insert('message_events', [
                'id' => Uuid::v7()->toRfc4122(),
                'message_id' => $messageId,
                'event_type' => $type,
                'event_source' => 'tracking_endpoint',
                'metadata_json' => null === $linkIndex ? '{}' : json_encode(['link_index' => $linkIndex], \JSON_THROW_ON_ERROR),
                'occurred_at' => Clock::now()->format('Y-m-d H:i:s.uP'),
            ]);

            return true;
        });
    }
}
