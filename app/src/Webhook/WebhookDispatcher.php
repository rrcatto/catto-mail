<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Entity\WebhookEndpoint;
use App\Version;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The Symfony webhook worker's engine (D-22, specification 2.8). PostgreSQL is the
 * only coordination medium; several workers may run at once.
 *
 * 1. Fan-out: pending outbox rows (webhook_events.fanned_out_at IS NULL) are locked
 *    FOR UPDATE SKIP LOCKED in bounded batches. For each, the client's endpoints
 *    that are enabled and subscribed to the event type at this moment (for
 *    webhook.test: the one addressed endpoint, if still enabled) each get one
 *    webhook_deliveries row holding the payload; the unique (event, endpoint) index
 *    with ON CONFLICT DO NOTHING is the final duplicate guarantee, and the event
 *    is marked fanned out in the same transaction. A crash before commit leaves
 *    the event pending; after commit the deliveries exist.
 * 2. Claim: due pending deliveries (next_attempt_at reached, no live lease) are
 *    claimed FOR UPDATE SKIP LOCKED: claimed_by, a lease, attempt_count + 1. The
 *    incremented attempt_count is the attempt's fencing token. No transaction is
 *    held during HTTP.
 * 3. Attempt: the destination is checked against the SSRF policy and pinned to the
 *    checked address; the body is the stored payload's exact bytes; requests of a
 *    batch run concurrently with bounded timeouts, no redirects and a bounded
 *    response read.
 * 4. Finalise: UPDATE ... WHERE id AND claimed_by AND attempt_count still match.
 *    A worker that lost its lease (crash, stall) changes nothing; the delivery is
 *    re-claimed after the lease expires and the request is sent again.
 *
 * HTTP delivery is at-least-once: a request whose result was not recorded is
 * repeated. Receivers de-duplicate on Smarthost-Event-Id.
 *
 * Retry policy: 2xx delivered; 408, 425, 429, 5xx and transport errors
 * (timeouts, refused or reset connections, TLS and temporary DNS failures) are
 * retried after min(APP_WEBHOOK_RETRY_MAX_SECONDS, BASE x 2^(attempt-1)) +-10%,
 * or after a sane Retry-After (1 s to the maximum); other responses (3xx, other
 * 4xx), refused destinations and disabled endpoints fail permanently. After
 * APP_WEBHOOK_MAX_ATTEMPTS attempts a delivery is failed. Failed deliveries are
 * kept for operators. Endpoints are never disabled automatically.
 */
final class WebhookDispatcher
{
    public const MAX_RESPONSE_BYTES = 65536;
    /** Response-header bound (Phase 8): larger header blocks fail the attempt (libcurl itself caps them at 300 KB). */
    public const MAX_RESPONSE_HEADER_BYTES = 16384;
    public const EXCERPT_CHARS = 1024;
    public const NOTIFY_CHANNEL = 'smarthost_webhook_work';

    /** @var array<string, int> counters since start */
    public array $stats = ['events_fanned_out' => 0, 'deliveries_created' => 0, 'attempts' => 0, 'delivered' => 0, 'retried' => 0, 'failed' => 0, 'lost_lease' => 0];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.webhook_connection')] private readonly Connection $db,
        #[Autowire(service: 'doctrine.orm.webhook_entity_manager')] private readonly EntityManagerInterface $em,
        private readonly WebhookPayloadFactory $payloads,
        private readonly WebhookSecrets $secrets,
        private readonly WebhookTargetGuard $guard,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.webhook.max_attempts%')] private readonly int $maxAttempts,
        #[Autowire('%app.webhook.timeout_seconds%')] private readonly int $timeoutSeconds,
        #[Autowire('%app.webhook.lease_seconds%')] private readonly int $leaseSeconds,
        #[Autowire('%app.webhook.retry_base_seconds%')] private readonly int $retryBaseSeconds,
        #[Autowire('%app.webhook.retry_max_seconds%')] private readonly int $retryMaxSeconds,
        #[Autowire('%app.webhook.connect_timeout_seconds%')] private ?int $connectTimeoutSeconds = null,
    ) {
        $this->connectTimeoutSeconds ??= min(5, $this->timeoutSeconds);
        if ($this->leaseSeconds <= $this->timeoutSeconds) {
            throw new \InvalidArgumentException('APP_WEBHOOK_LEASE_SECONDS must exceed APP_WEBHOOK_TIMEOUT_SECONDS.');
        }
        if ($this->connectTimeoutSeconds < 1 || $this->connectTimeoutSeconds > $this->timeoutSeconds) {
            throw new \InvalidArgumentException('APP_WEBHOOK_CONNECT_TIMEOUT_SECONDS must be between 1 and APP_WEBHOOK_TIMEOUT_SECONDS.');
        }
    }

    /** Expands up to $limit pending outbox events into deliveries; returns the number of events processed. */
    public function fanOut(int $limit = 100): int
    {
        return $this->db->transactional(function (Connection $c) use ($limit): int {
            $events = $c->fetchAllAssociative(<<<'SQL'
                SELECT id::text AS id, client_id::text AS client_id, event_type, subject_type, subject_id::text AS subject_id, created_at
                  FROM webhook_events WHERE fanned_out_at IS NULL ORDER BY created_at, id LIMIT ? FOR UPDATE SKIP LOCKED
                SQL, [$limit]);
            foreach ($events as $event) {
                $endpoints = $this->targets($c, $event);
                $payload = [] === $endpoints ? null : $this->payloads->build($event);
                if ([] !== $endpoints && null === $payload) {
                    $this->logger->warning('Webhook event subject not found; no delivery created.', ['webhook_event_id' => $event['id'], 'client_id' => $event['client_id']]);
                }
                foreach (null === $payload ? [] : $endpoints as $endpointId) {
                    $json = WebhookPayloadFactory::encode($payload);
                    $this->stats['deliveries_created'] += $c->executeStatement(<<<'SQL'
                        INSERT INTO webhook_deliveries (id, client_id, webhook_event_id, webhook_endpoint_id, event_type, payload_json, payload_hash,
                                                        status, next_attempt_at, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, CAST(? AS jsonb), encode(sha256(convert_to(CAST(? AS jsonb)::text, 'UTF8')), 'hex'), 'pending', now(), now(), now())
                        ON CONFLICT (webhook_event_id, webhook_endpoint_id) DO NOTHING
                        SQL, [Uuid::v7()->toRfc4122(), $event['client_id'], $event['id'], $endpointId, $event['event_type'], $json, $json]);
                }
                $c->executeStatement('UPDATE webhook_events SET fanned_out_at = now() WHERE id = ?', [$event['id']]);
                ++$this->stats['events_fanned_out'];
            }

            return \count($events);
        });
    }

    /**
     * Fails deliveries whose final attempt's lease expired (the worker died during
     * it): they are not re-claimed beyond APP_WEBHOOK_MAX_ATTEMPTS.
     */
    public function failExhaustedLeases(): int
    {
        return $this->db->executeStatement(<<<'SQL'
            UPDATE webhook_deliveries SET status = 'failed', claimed_by = NULL, lease_expires_at = NULL, next_attempt_at = NULL, updated_at = now(),
                   last_error = left('Attempts exhausted; the worker making the last attempt stopped before recording its result.', 1000)
             WHERE status = 'pending' AND attempt_count >= ? AND lease_expires_at < now()
            SQL, [$this->maxAttempts]);
    }

    /**
     * Claims up to $limit due deliveries for $workerId.
     *
     * @return list<array{id: string, attempt: int, endpoint_id: string, event_id: string, event_type: string, body: string, payload_hash: string}>
     */
    public function claim(string $workerId, int $limit = 10): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            UPDATE webhook_deliveries d
               SET claimed_by = ?, lease_expires_at = now() + make_interval(secs => ?), attempt_count = d.attempt_count + 1,
                   last_attempt_at = now(), updated_at = now()
             WHERE d.id IN (SELECT id FROM webhook_deliveries
                             WHERE status = 'pending' AND attempt_count < ? AND (next_attempt_at IS NULL OR next_attempt_at <= now())
                               AND (lease_expires_at IS NULL OR lease_expires_at < now())
                             ORDER BY next_attempt_at NULLS FIRST, created_at, id LIMIT ? FOR UPDATE SKIP LOCKED)
            RETURNING d.id::text AS id, d.attempt_count AS attempt, d.webhook_endpoint_id::text AS endpoint_id, d.webhook_event_id::text AS event_id,
                      d.event_type, d.payload_json::text AS body, d.payload_hash
            SQL, [$workerId, $this->leaseSeconds, $this->maxAttempts, $limit]);

        return array_map(static fn (array $r): array => ['attempt' => (int) $r['attempt']] + $r, $rows);
    }

    /**
     * Sends the claimed deliveries concurrently and records each outcome (fenced).
     *
     * @param list<array{id: string, attempt: int, endpoint_id: string, event_id: string, event_type: string, body: string, payload_hash: string}> $claims
     */
    public function attempt(string $workerId, array $claims): void
    {
        $inFlight = [];
        foreach ($claims as $claim) {
            ++$this->stats['attempts'];
            $endpoint = $this->em->find(WebhookEndpoint::class, Uuid::fromString($claim['endpoint_id']));
            if (null === $endpoint || !$endpoint->isEnabled()) {
                $this->finish($workerId, $claim, 'failed', null, 'The endpoint was disabled before delivery.', null);
                continue;
            }
            if (!hash_equals($claim['payload_hash'], hash('sha256', $claim['body']))) {
                $this->finish($workerId, $claim, 'failed', null, 'The stored payload does not match its hash.', null);
                continue;
            }
            try {
                $target = $this->guard->check($endpoint->getUrl());
            } catch (WebhookTargetRefused $e) {
                $this->finish($workerId, $claim, 'failed', null, $e->getMessage(), null);
                continue;
            } catch (\RuntimeException $e) {
                $this->retryOrFail($workerId, $claim, null, 'DNS: '.$e->getMessage(), null, null);
                continue;
            }
            $timestamp = time();
            try {
                $signature = WebhookSigner::header($claim['body'], $this->secrets->active($endpoint), $timestamp);
            } catch (\RuntimeException) {
                $this->finish($workerId, $claim, 'failed', null, 'The signing secret could not be decrypted (keyring).', null);
                continue;
            }
            $response = $this->http->request('POST', $endpoint->getUrl(), [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Catto-Mail-Smarthost/'.Version::VERSION,
                    'Smarthost-Event-Id' => $claim['event_id'],
                    'Smarthost-Event-Type' => $claim['event_type'],
                    'Smarthost-Delivery-Id' => $claim['id'],
                    'Smarthost-Delivery-Attempt' => (string) $claim['attempt'],
                    'Smarthost-Signature' => $signature,
                ],
                'body' => $claim['body'],
                'resolve' => [$target->host => $target->ip],
                'max_redirects' => 0,
                'timeout' => (float) $this->timeoutSeconds,
                'max_duration' => (float) $this->timeoutSeconds,
                'max_connect_duration' => (float) $this->connectTimeoutSeconds,
                'buffer' => false,
                'verify_peer' => true,
                'verify_host' => true,
                'user_data' => $claim,
            ]);
            $inFlight[] = $response;
            $this->em->clear();
        }
        $this->collect($workerId, $inFlight);
        $this->em->clear();
    }

    /** @param list<ResponseInterface> $responses */
    private function collect(string $workerId, array $responses): void
    {
        $bodies = [];
        $done = [];
        if ([] === $responses) {
            return;
        }
        try {
            foreach ($this->http->stream($responses, (float) $this->timeoutSeconds + 1) as $response => $chunk) {
                $claim = $response->getInfo('user_data');
                $id = $claim['id'];
                if (isset($done[$id])) {
                    continue;
                }
                try {
                    if ($chunk->isTimeout()) {
                        $response->cancel();
                        $done[$id] = true;
                        $this->retryOrFail($workerId, $claim, null, 'Timed out waiting for the endpoint.', null, null);
                        continue;
                    }
                    if ($chunk->isFirst()) {
                        $response->getStatusCode(); // acknowledges non-2xx statuses so the stream does not throw
                        $headerBytes = array_sum(array_map('strlen', (array) $response->getInfo('response_headers')));
                        if ($headerBytes > self::MAX_RESPONSE_HEADER_BYTES) {
                            $response->cancel();
                            $done[$id] = true;
                            $this->retryOrFail($workerId, $claim, null, \sprintf('Response headers exceed %d bytes.', self::MAX_RESPONSE_HEADER_BYTES), null, null);
                            continue;
                        }
                    }
                    $content = $chunk->getContent();
                    $bodies[$id] = ($bodies[$id] ?? '').$content;
                    if ($chunk->isLast() || \strlen($bodies[$id]) >= self::MAX_RESPONSE_BYTES) {
                        if (!$chunk->isLast()) {
                            $response->cancel();
                        }
                        $done[$id] = true;
                        $this->outcome($workerId, $claim, $response, $bodies[$id]);
                    }
                } catch (TransportExceptionInterface $e) {
                    $done[$id] = true;
                    $this->retryOrFail($workerId, $claim, null, 'Transport: '.self::short($e->getMessage()), null, null);
                } catch (HttpExceptionInterface) {
                    $done[$id] = true;
                    $this->outcome($workerId, $claim, $response, $bodies[$id] ?? '');
                }
            }
        } catch (TransportExceptionInterface|HttpExceptionInterface $e) {
            $this->logger->warning('Webhook stream error.', ['error' => self::short($e->getMessage())]);
        }
        foreach ($responses as $response) {
            $claim = $response->getInfo('user_data');
            if (!isset($done[$claim['id']])) {
                $this->retryOrFail($workerId, $claim, null, 'No complete response.', null, null);
            }
        }
    }

    private function outcome(string $workerId, array $claim, ResponseInterface $response, string $body): void
    {
        $status = $response->getStatusCode();
        $excerpt = self::excerpt($body);
        if ($status >= 200 && $status < 300) {
            $this->finish($workerId, $claim, 'delivered', $status, null, $excerpt);

            return;
        }
        $headers = $response->getHeaders(false);
        if (\in_array($status, [408, 425, 429], true) || $status >= 500) {
            $this->retryOrFail($workerId, $claim, $status, "HTTP $status", $excerpt, self::retryAfter($headers['retry-after'][0] ?? null));

            return;
        }
        $this->finish($workerId, $claim, 'failed', $status,
            $status >= 300 && $status < 400 ? "HTTP $status: redirects are not followed." : "HTTP $status (not retried).", $excerpt);
    }

    private function retryOrFail(string $workerId, array $claim, ?int $status, string $error, ?string $excerpt, ?int $retryAfter): void
    {
        if ($claim['attempt'] >= $this->maxAttempts) {
            $this->finish($workerId, $claim, 'failed', $status, $error.' Attempts exhausted.', $excerpt);

            return;
        }
        $delay = null !== $retryAfter ? max(1, min($this->retryMaxSeconds, $retryAfter)) : $this->backoff($claim['attempt']);
        $this->finish($workerId, $claim, 'pending', $status, $error, $excerpt, $delay);
    }

    public function backoff(int $attempt): int
    {
        $base = min($this->retryMaxSeconds, $this->retryBaseSeconds * (2 ** max(0, min(30, $attempt - 1))));
        $jitter = (int) round($base * (random_int(-100, 100) / 1000));

        return max(1, min($this->retryMaxSeconds, $base + $jitter));
    }

    private function finish(string $workerId, array $claim, string $status, ?int $httpStatus, ?string $error, ?string $excerpt, ?int $retryInSeconds = null): void
    {
        $updated = $this->db->executeStatement(<<<'SQL'
            UPDATE webhook_deliveries
               SET status = ?, last_response_status = ?, last_error = ?, last_response_excerpt = ?,
                   delivered_at = CASE WHEN ? = 'delivered' THEN now() ELSE delivered_at END,
                   next_attempt_at = CASE WHEN ? = 'pending' THEN now() + make_interval(secs => ?) ELSE NULL END,
                   claimed_by = NULL, lease_expires_at = NULL, updated_at = now()
             WHERE id = ? AND claimed_by = ? AND attempt_count = ? AND status = 'pending'
            SQL, [$status, $httpStatus, null === $error ? null : mb_substr($error, 0, 1000), $excerpt, $status, $status, $retryInSeconds ?? 0,
                $claim['id'], $workerId, $claim['attempt']]);
        if (0 === $updated) {
            ++$this->stats['lost_lease'];
            $this->logger->warning('Webhook delivery lease lost; result not recorded.', ['webhook_delivery_id' => $claim['id'], 'attempt' => $claim['attempt']]);

            return;
        }
        ++$this->stats['delivered' === $status ? 'delivered' : ('pending' === $status ? 'retried' : 'failed')];
        $this->logger->info('Webhook delivery attempt recorded.', ['webhook_delivery_id' => $claim['id'], 'webhook_event_id' => $claim['event_id'],
            'attempt' => $claim['attempt'], 'status' => $status, 'http_status' => $httpStatus, 'retry_in_seconds' => $retryInSeconds]);
    }

    /**
     * Endpoints that receive the event, evaluated now (fan-out time).
     *
     * @return list<string>
     */
    private function targets(Connection $c, array $event): array
    {
        if ('webhook.test' === $event['event_type']) {
            // Addressed to exactly one endpoint (its subject), still the client's and still enabled.
            return $c->fetchFirstColumn(<<<'SQL'
                SELECT id::text FROM webhook_endpoints WHERE client_id = ? AND status = 'enabled' AND id = CAST(? AS uuid)
                SQL, [$event['client_id'], $event['subject_id']]);
        }

        return $c->fetchFirstColumn(<<<'SQL'
            SELECT id::text FROM webhook_endpoints WHERE client_id = ? AND status = 'enabled'
               AND subscribed_event_types @> jsonb_build_array(CAST(? AS text)) ORDER BY id
            SQL, [$event['client_id'], $event['event_type']]);
    }

    public static function retryAfter(?string $value): ?int
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        $value = trim($value);
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $at = \DateTimeImmutable::createFromFormat(\DATE_RFC7231, $value);

        return false === $at ? null : max(0, $at->getTimestamp() - time());
    }

    private static function excerpt(string $body): ?string
    {
        if ('' === $body) {
            return null;
        }
        $text = mb_convert_encoding(substr($body, 0, 4 * self::EXCERPT_CHARS), 'UTF-8', 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return mb_substr($text, 0, self::EXCERPT_CHARS);
    }

    private static function short(string $message): string
    {
        return mb_substr((string) preg_replace('/\s+/', ' ', $message), 0, 300);
    }
}
