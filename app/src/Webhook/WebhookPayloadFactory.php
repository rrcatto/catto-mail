<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Api\Presenter;
use App\Entity\Message;
use App\Entity\MessageEvent;
use App\Entity\SendJob;
use App\Entity\ValidationJob;
use App\Enum\MessageEventType;
use App\Util\Clock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Builds the canonical OpenAPI `WebhookEvent` for an outbox row, with the same
 * presenters as the /v1 API (never Doctrine serialization):
 *
 *   validation.*  data = ValidationJob
 *   send.*        data = SendJob
 *   message.*     data = {message: Message, event: MessageEvent} - the event is the
 *                 message's first hard_bounce / complaint event, the one that moved it
 *   webhook.test  data = {}
 *
 * Nothing secret is included (no API keys, webhook secrets, tracking or VERP
 * tokens). The data is the subject's state when the event is fanned out; the
 * client polls the API for later state. Reads go through the webhook worker's own
 * database role (the `webhook` entity manager).
 */
final class WebhookPayloadFactory
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.webhook_entity_manager')] private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array{id: string, event_type: string, subject_type: string, subject_id: string, created_at: string, client_id: string} $event
     *
     * @return array<string, mixed>|null the WebhookEvent, or null when the subject no longer exists for this client
     */
    public function build(array $event): ?array
    {
        $data = match ($event['event_type']) {
            'validation.completed', 'validation.failed' => $this->validationJob($event),
            'send.completed', 'send.failed' => $this->sendJob($event),
            'message.hard_bounced' => $this->message($event, MessageEventType::HardBounce),
            'message.complained' => $this->message($event, MessageEventType::Complaint),
            'webhook.test' => new \stdClass(),
            'repermission.responded' => $this->repermission($event),
            default => null,
        };
        $this->em->clear();
        if (null === $data) {
            return null;
        }

        return [
            'id' => $event['id'],
            'type' => $event['event_type'],
            'created_at' => Clock::rfc3339(new \DateTimeImmutable($event['created_at'])),
            'data' => $data,
        ];
    }

    /** The exact bytes sent and signed (the canonical JSON encoding of the stored payload). */
    public static function encode(array|\stdClass $payload): string
    {
        return json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed>|null */
    private function validationJob(array $event): ?array
    {
        $job = $this->em->find(ValidationJob::class, Uuid::fromString($event['subject_id']));

        return null !== $job && $job->getClient()->getId()->toRfc4122() === $event['client_id'] ? Presenter::validationJob($job) : null;
    }

    /** @return array<string, mixed>|null */
    private function sendJob(array $event): ?array
    {
        $job = $this->em->find(SendJob::class, Uuid::fromString($event['subject_id']));

        return null !== $job && $job->getClient()->getId()->toRfc4122() === $event['client_id'] ? Presenter::sendJob($job) : null;
    }

    /**
     * A recipient's answer to a re-permission message (specification 2.11), read as it is
     * at fan-out time: the payload carries the current answer and when it was given.
     *
     * @return array<string, mixed>|null
     */
    private function repermission(array $event): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative(<<<'SQL'
            SELECT e.id::text AS entry_id, e.normalized_address, e.consent_state, e.consent_changed_at,
                   b.id::text AS batch_id, b.name, b.list_id, b.client_id::text AS client_id
              FROM address_batch_entries e JOIN address_batches b ON b.id = e.batch_id
             WHERE e.id = ?
            SQL, [$event['subject_id']]);
        if (false === $row || $row['client_id'] !== $event['client_id']
            || !\in_array($row['consent_state'], ['confirmed', 'unsubscribed', 'global_opt_out'], true) || null === $row['consent_changed_at']) {
            return null;
        }

        return [
            'batch' => ['id' => $row['batch_id'], 'name' => (string) $row['name'], 'list_id' => (string) $row['list_id']],
            'entry_id' => $row['entry_id'],
            'address' => (string) $row['normalized_address'],
            'response' => $row['consent_state'],
            'responded_at' => Clock::rfc3339(new \DateTimeImmutable((string) $row['consent_changed_at'])),
        ];
    }

    /** @return array<string, mixed>|null */
    private function message(array $event, MessageEventType $type): ?array
    {
        $message = $this->em->find(Message::class, Uuid::fromString($event['subject_id']));
        if (null === $message || $message->getSendJob()->getClient()->getId()->toRfc4122() !== $event['client_id']) {
            return null;
        }
        $trigger = $this->em->createQuery('SELECT e FROM App\Entity\MessageEvent e WHERE e.message = :m AND e.eventType = :t ORDER BY e.occurredAt ASC, e.id ASC')
            ->setParameter('m', $message->getId(), 'uuid')->setParameter('t', $type->value)->setMaxResults(1)->getOneOrNullResult();
        if (!$trigger instanceof MessageEvent) {
            return null;
        }

        return ['message' => Presenter::message($message), 'event' => Presenter::messageEvent($trigger)];
    }
}
