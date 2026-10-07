<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Client\AccountAdministration;
use App\Enum\ClientStatus;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;

/**
 * D-31: only active and throttled clients may create or add work. Pending-approval
 * and suspended clients get 403 on the four work-creating operations but can still
 * authenticate and read; closed clients cannot authenticate.
 */
final class ClientStatusTest extends ApiTestCase
{
    /** @return array{0: string, 1: string, 2: string, 3: \App\Entity\Client} key, validation job id, collecting send job id, client */
    private function clientWithWork(): array
    {
        [$client, $key] = $this->newApiClient();
        $domain = $this->verifiedDomain($client);
        $validation = self::json($this->api('POST', '/v1/validation-jobs', $key, ['addresses' => [['address' => 'a@example.com']]], ['Idempotency-Key' => self::key()]))['id'];
        $job = $this->createSendJob($key, $domain);
        self::assertSame(201, $this->addBatch($key, $job, self::recipients(0, 1))->getStatusCode());

        return [$key, $validation, $job, $client];
    }

    private function changeStatus(\App\Entity\Client $client, ClientStatus $status): void
    {
        $this->setClientStatus($client, $status);
    }

    public function testPendingAndSuspendedClientsCannotCreateOrAddWork(): void
    {
        foreach ([ClientStatus::PendingApproval, ClientStatus::Suspended] as $status) {
            [$key, $validation, $job, $client] = $this->clientWithWork();
            $this->changeStatus($client, $status);
            $sender = Db::owner()->fetchOne('SELECT domain FROM sending_domains WHERE client_id = ?', [$client->getId()->toRfc4122()]);
            $before = Db::owner()->fetchAssociative('SELECT status, total_recipients FROM send_jobs WHERE id = ?', [$job]);

            $this->assertProblem($this->api('POST', '/v1/validation-jobs', $key, ['addresses' => [['address' => 'b@example.com']]], ['Idempotency-Key' => self::key()]), 403, 'forbidden');
            $this->assertProblem($this->api('POST', '/v1/send-jobs', $key, ['external_reference' => 'x', 'message_class' => 'transactional',
                'sender_identity' => ['email' => 'a@'.$sender]], ['Idempotency-Key' => self::key()]), 403, 'forbidden');
            $this->assertProblem($this->addBatch($key, $job, self::recipients(5, 1)), 403, 'forbidden');
            $this->assertProblem($this->api('POST', "/v1/send-jobs/$job/submit", $key), 403, 'forbidden');

            // Nothing changed, and existing resources remain readable.
            self::assertSame($before, Db::owner()->fetchAssociative('SELECT status, total_recipients FROM send_jobs WHERE id = ?', [$job]));
            self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM validation_jobs WHERE client_id = ?', [$client->getId()->toRfc4122()]));
            $this->assertContract($this->api('GET', "/v1/validation-jobs/$validation", $key), 200, '/validation-jobs/{id}', 'get');
            $this->assertContract($this->api('GET', "/v1/validation-jobs/$validation/addresses", $key), 200, '/validation-jobs/{id}/addresses', 'get');
            $this->assertContract($this->api('GET', "/v1/send-jobs/$job", $key), 200, '/send-jobs/{id}', 'get');
            $this->assertContract($this->api('GET', "/v1/send-jobs/$job/messages", $key), 200, '/send-jobs/{id}/messages', 'get');
        }
    }

    public function testForbiddenRequestsHaveNoIdempotentSideEffect(): void
    {
        [$key, , , $client] = $this->clientWithWork();
        $this->changeStatus($client, ClientStatus::Suspended);
        $idem = self::key();
        $body = ['addresses' => [['address' => 'later@example.com']]];
        $this->assertProblem($this->api('POST', '/v1/validation-jobs', $key, $body, ['Idempotency-Key' => $idem]), 403);
        $this->changeStatus($client, ClientStatus::Active);
        $r = $this->api('POST', '/v1/validation-jobs', $key, $body, ['Idempotency-Key' => $idem]);
        self::assertSame(202, $r->getStatusCode());
        self::assertNull($r->headers->get('Idempotent-Replayed'), 'the forbidden attempt stored nothing');
    }

    public function testThrottledClientsMayStillCreateWork(): void
    {
        [$key, , $job, $client] = $this->clientWithWork();
        $this->changeStatus($client, ClientStatus::Throttled);
        self::assertSame(202, $this->api('POST', '/v1/validation-jobs', $key, ['addresses' => [['address' => 'c@example.com']]], ['Idempotency-Key' => self::key()])->getStatusCode());
        self::assertSame(201, $this->addBatch($key, $job, self::recipients(9, 1))->getStatusCode());
        self::assertSame(202, $this->api('POST', "/v1/send-jobs/$job/submit", $key)->getStatusCode());
    }

    public function testClosedClientsCannotAuthenticate(): void
    {
        [$key, $validation, , $client] = $this->clientWithWork();
        $this->changeStatus($client, ClientStatus::Closed);
        $this->assertProblem($this->api('GET', "/v1/validation-jobs/$validation", $key), 401, 'unauthorized');
    }

    public function testValidationJobCreationNotifiesTheValidator(): void
    {
        [, $key] = $this->newApiClient();
        $listener = new \Pdo\Pgsql(\sprintf('pgsql:host=%s;port=%s;dbname=%s', Db::env('SMARTHOST_DB_HOST'), Db::env('SMARTHOST_DB_PORT'), Db::databaseName()),
            Db::env('APP_DB_USER'), Db::env('APP_DB_PASSWORD'));
        $listener->exec('LISTEN smarthost_validation_work');
        $id = self::json($this->api('POST', '/v1/validation-jobs', $key, ['addresses' => [['address' => 'n@example.com']]], ['Idempotency-Key' => self::key()]))['id'];
        $notification = $listener->getNotify(\PDO::FETCH_ASSOC, 5000);
        self::assertSame('smarthost_validation_work', $notification['message'] ?? null);
        self::assertSame($id, $notification['payload'] ?? null);
    }
}
