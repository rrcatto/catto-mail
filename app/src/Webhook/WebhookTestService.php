<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Audit\AuditActor;
use App\Audit\AuditLogger;
use App\Entity\Client;
use App\Entity\WebhookEndpoint;
use App\Enum\WebhookEventType;
use App\Enum\WebhookSubjectType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Records a `webhook.test` event (POST /v1/webhooks/test and the client dashboard).
 * Never performs HTTP itself: the event goes to the transactional outbox, the
 * webhook worker is woken (NOTIFY) and delivers it like any other event. Addressed
 * to exactly one endpoint (subject_type webhook_endpoint); webhook.test ignores
 * subscriptions (it is not subscribable).
 */
final class WebhookTestService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly WebhookOutbox $outbox,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return Uuid|null the event id, or null when the endpoint is disabled (nothing is recorded) */
    public function request(Client $client, WebhookEndpoint $endpoint, AuditActor $actor): ?Uuid
    {
        if (!$endpoint->getClient()->getId()->equals($client->getId())) {
            throw new \LogicException('A webhook test is only ever addressed to one of the client\'s own endpoints.');
        }
        if (!$endpoint->isEnabled()) {
            return null;
        }

        return $this->connection->transactional(function (Connection $c) use ($client, $endpoint, $actor): Uuid {
            $id = $this->outbox->record($client->getId(), WebhookEventType::WebhookTest, WebhookSubjectType::WebhookEndpoint, $endpoint->getId())
                ?? throw new \LogicException('webhook.test is never de-duplicated.');
            $this->audit->record($actor, 'webhook.test_requested', 'webhook_event', $id->toRfc4122(), [
                'client_id' => $client->getId()->toRfc4122(), 'webhook_endpoint_id' => $endpoint->getId()->toRfc4122()]);
            $c->executeStatement('SELECT pg_notify(?, ?)', [WebhookDispatcher::NOTIFY_CHANNEL, $id->toRfc4122()]);

            return $id;
        });
    }
}
