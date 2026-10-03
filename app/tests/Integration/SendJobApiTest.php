<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\ClientStatus;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Db;

/** Staged send-job ingestion (D-24): create, recipient batches, submit. Nothing is sent. */
final class SendJobApiTest extends ApiTestCase
{
    public function testCreateReturnsACollectingJob(): void
    {
        [$client, $key] = $this->newApiClient();
        $domain = $this->verifiedDomain($client);
        $r = $this->api('POST', '/v1/send-jobs', $key, ['external_reference' => 'welcome-2026-10', 'message_class' => 'transactional',
            'sender_identity' => ['email' => 'Notify@'.strtoupper($domain->getDomain()), 'name' => 'Notifications'],
            'reply_to' => ['email' => 'support@example.org', 'name' => 'Support'], 'tracking' => ['opens' => true]], ['Idempotency-Key' => self::key()]);
        $job = $this->assertContract($r, 201, '/send-jobs', 'post');
        self::assertSame('/v1/send-jobs/'.$job['id'], $r->headers->get('Location'));
        self::assertSame('collecting', $job['status']);
        self::assertSame(0, $job['total_recipients']);
        self::assertSame(['email' => 'Notify@'.$domain->getDomain(), 'name' => 'Notifications'], $job['sender_identity'], 'domain normalised, local part kept');
        self::assertSame(['opens' => true, 'clicks' => false], $job['tracking']);
        self::assertSame(0, array_sum($job['summary_counts']));
        self::assertCount(11, $job['summary_counts']);
        self::assertNull($job['queued_at']);
        $row = Db::owner()->fetchAssociative('SELECT sending_domain_id, client_id FROM send_jobs WHERE id = ?', [$job['id']]);
        self::assertSame($domain->getId()->toRfc4122(), $row['sending_domain_id']);
        self::assertSame($client->getId()->toRfc4122(), $row['client_id']);
    }

    public function testListIdRulesAndProhibitedFields(): void
    {
        [$client, $key] = $this->newApiClient();
        $domain = $this->verifiedDomain($client);
        $base = ['external_reference' => 'x', 'sender_identity' => ['email' => 'a@'.$domain->getDomain()]];
        $post = fn (array $b) => $this->api('POST', '/v1/send-jobs', $key, $b, ['Idempotency-Key' => self::key()]);
        $p = $this->assertProblem($post($base + ['message_class' => 'subscription']), 422, 'validation-error');
        self::assertSame([['pointer' => '/list_id', 'message' => 'Field "list_id" is required.']], $p['errors']);
        $p = $this->assertProblem($post($base + ['message_class' => 'transactional', 'list_id' => 'l.example']), 422, 'validation-error');
        self::assertSame([['pointer' => '/list_id', 'message' => 'Field "list_id" is not accepted here.']], $p['errors']);
        self::assertSame(201, $post($base + ['message_class' => 'subscription', 'list_id' => 'news.example'])->getStatusCode());
        foreach (['client_id' => $client->getId()->toRfc4122(), 'headers' => ['X-Custom' => '1'], 'template_reference' => 'tpl-1',
            'merge_data' => ['name' => 'x'], 'subject' => 'job-level subject'] as $field => $value) {
            $p = $this->assertProblem($post($base + ['message_class' => 'transactional', $field => $value]), 422, 'validation-error');
            self::assertContains("/$field", array_column($p['errors'], 'pointer'));
        }
        $p = $this->assertProblem($post(array_replace($base, ['message_class' => 'transactional', 'sender_identity' => ['email' => 'a@'.$domain->getDomain(), 'name' => "Evil\r\nBcc: x@y.z"]])), 422);
        self::assertSame('/sender_identity/name', $p['errors'][0]['pointer']);
    }

    public function testSenderDomainMustBeARegisteredEnabledDomainOfTheClient(): void
    {
        [$client, $key] = $this->newApiClient();
        $post = fn (string $email) => $this->api('POST', '/v1/send-jobs', $key, ['external_reference' => 'x', 'message_class' => 'transactional',
            'sender_identity' => ['email' => $email]], ['Idempotency-Key' => self::key()]);
        $this->assertProblem($post('a@unregistered.example'), 422, 'validation-error');
        $pending = $this->pendingDomain($client);
        self::assertSame(201, $post('a@'.$pending->getDomain())->getStatusCode(), 'verification is enforced at submit, not create');
        $this->container()->get(\App\Domain\SendingDomainService::class)->disable($this->reload($pending), self::actor());
        $this->assertProblem($post('b@'.$pending->getDomain()), 422, 'validation-error');
    }

    public function testClientsThatMayNotSendAreForbidden(): void
    {
        foreach ([ClientStatus::PendingApproval, ClientStatus::Suspended] as $status) {
            [$client, $key] = $this->newApiClient($status);
            $domain = $this->verifiedDomain($client);
            $r = $this->api('POST', '/v1/send-jobs', $key, ['external_reference' => 'x', 'message_class' => 'transactional',
                'sender_identity' => ['email' => 'a@'.$domain->getDomain()]], ['Idempotency-Key' => self::key()]);
            $this->assertProblem($r, 403, 'forbidden');
        }
    }

    public function testBatchSizeBoundary(): void
    {
        [$client, $key] = $this->newApiClient();
        $job = $this->createSendJob($key, $this->verifiedDomain($client));
        $p = $this->assertProblem($this->addBatch($key, $job, self::recipients(0, 501)), 422, 'validation-error');
        self::assertSame('/recipients', $p['errors'][0]['pointer']);
        $ok = $this->assertContract($this->addBatch($key, $job, self::recipients(0, 500)), 201, '/send-jobs/{id}/recipients', 'post');
        self::assertSame(500, $ok['accepted_count']);
        self::assertSame(500, $ok['total_recipients']);
        $this->assertProblem($this->addBatch($key, $job, []), 422, 'validation-error');
    }

    public function testConfiguredBatchLimitMayOnlyLowerTheContract(): void
    {
        $this->withEnv(['APP_SEND_JOB_MAX_RECIPIENTS_PER_BATCH' => '10'], function (): void {
            [$client, $key] = $this->newApiClient();
            $job = $this->createSendJob($key, $this->verifiedDomain($client));
            $this->assertProblem($this->addBatch($key, $job, self::recipients(0, 11)), 422, 'batch-too-large');
            self::assertSame(201, $this->addBatch($key, $job, self::recipients(0, 10))->getStatusCode());
        });
    }

    public function testJobTotalBoundary(): void
    {
        [$client, $key] = $this->newApiClient();
        $job = $this->createSendJob($key, $this->verifiedDomain($client));
        for ($b = 0; $b < 20; ++$b) {
            self::assertSame(201, $this->addBatch($key, $job, self::recipients($b * 500, 500))->getStatusCode());
        }
        self::assertSame(10000, (int) Db::owner()->fetchOne('SELECT total_recipients FROM send_jobs WHERE id = ?', [$job]));
        $this->assertProblem($this->addBatch($key, $job, self::recipients(10000, 1)), 422, 'recipient-limit-exceeded');
        self::assertSame(10000, (int) Db::owner()->fetchOne('SELECT count(*) FROM send_job_recipients WHERE send_job_id = ?', [$job]));
        $sealed = $this->assertContract($this->api('POST', "/v1/send-jobs/$job/submit", $key), 202, '/send-jobs/{id}/submit', 'post');
        self::assertSame(10000, $sealed['total_recipients']);
    }

    public function testConfiguredJobLimit(): void
    {
        $this->withEnv(['APP_SEND_JOB_MAX_RECIPIENTS' => '5'], function (): void {
            [$client, $key] = $this->newApiClient();
            $job = $this->createSendJob($key, $this->verifiedDomain($client));
            self::assertSame(201, $this->addBatch($key, $job, self::recipients(0, 5))->getStatusCode());
            $this->assertProblem($this->addBatch($key, $job, self::recipients(5, 1)), 422, 'recipient-limit-exceeded');
        });
    }

    public function testDuplicatesWithinABatchAfterD18Normalisation(): void
    {
        [$client, $key] = $this->newApiClient();
        $job = $this->createSendJob($key, $this->verifiedDomain($client));
        $p = $this->assertProblem($this->addBatch($key, $job, [
            self::recipient(0, ['email_address' => 'John@example.com']),
            self::recipient(1, ['email_address' => 'john@example.com']),
            self::recipient(2, ['email_address' => ' John@Example.COM']),
        ]), 422, 'validation-error');
        self::assertSame([['pointer' => '/recipients/2/email_address', 'message' => 'Duplicate of /recipients/0/email_address after normalisation.']], $p['errors']);
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM send_job_recipients WHERE send_job_id = ?', [$job]), 'the batch is rejected as a whole');
    }

    public function testDuplicatesAcrossBatchesAndLocalPartCase(): void
    {
        [$client, $key] = $this->newApiClient();
        $job = $this->createSendJob($key, $this->verifiedDomain($client));
        self::assertSame(201, $this->addBatch($key, $job, [self::recipient(0, ['email_address' => 'John@Example.com'])])->getStatusCode());
        $p = $this->assertProblem($this->addBatch($key, $job, [
            self::recipient(1, ['email_address' => 'john@example.com']),
            self::recipient(2, ['email_address' => "John@EXAMPLE.com\t"]),
        ]), 422, 'validation-error');
        self::assertSame(['/recipients/1/email_address'], array_column($p['errors'], 'pointer'), 'John@EXAMPLE.com duplicates; john@ does not');
        self::assertSame(201, $this->addBatch($key, $job, [self::recipient(1, ['email_address' => 'john@example.com'])])->getStatusCode());
        $rows = Db::owner()->fetchAllAssociative('SELECT email_address, normalized_address FROM send_job_recipients WHERE send_job_id = ? ORDER BY id', [$job]);
        self::assertSame([['email_address' => 'John@Example.com', 'normalized_address' => 'John@example.com'],
            ['email_address' => 'john@example.com', 'normalized_address' => 'john@example.com']], $rows);
    }

    public function testRenderedContentForms(): void
    {
        [$client, $key] = $this->newApiClient();
        $job = $this->createSendJob($key, $this->verifiedDomain($client));
        $html = self::recipient(0, ['html_body' => '<p>Hi</p>']);
        unset($html['text_body']);
        $text = self::recipient(1);
        $both = self::recipient(2, ['html_body' => '<p>Hi</p>']);
        self::assertSame(201, $this->addBatch($key, $job, [$html, $text, $both])->getStatusCode());
        $neither = self::recipient(3);
        unset($neither['text_body']);
        $p = $this->assertProblem($this->addBatch($key, $job, [$neither]), 422, 'validation-error');
        self::assertSame('/recipients/0', $p['errors'][0]['pointer']);

        $rows = Db::owner()->fetchAllAssociative('SELECT subject, html_body, text_body, content_bytes, content_sha256 FROM send_job_recipients WHERE send_job_id = ? ORDER BY id', [$job]);
        self::assertSame(['<p>Hi</p>', null], [$rows[0]['html_body'], $rows[0]['text_body']]);
        self::assertSame([null, 'Body for recipient 1'], [$rows[1]['html_body'], $rows[1]['text_body']]);
        self::assertSame(['<p>Hi</p>', 'Body for recipient 2'], [$rows[2]['html_body'], $rows[2]['text_body']]);
        self::assertSame(\strlen('Hello 2') + \strlen('<p>Hi</p>') + \strlen('Body for recipient 2'), (int) $rows[2]['content_bytes']);
        self::assertSame(hash('sha256', json_encode(['Hello 2', '<p>Hi</p>', 'Body for recipient 2'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)), $rows[2]['content_sha256']);
    }

    public function testMergeTemplateAndHeaderConceptsAreRejected(): void
    {
        [$client, $key] = $this->newApiClient();
        $job = $this->createSendJob($key, $this->verifiedDomain($client));
        foreach (['headers' => ['X-Priority' => '1'], 'merge_data' => ['first_name' => 'Ann'], 'template_reference' => 'welcome',
            'template_id' => 'x', 'variables' => ['a' => 1], 'cc' => 'x@y.z', 'from' => 'x@y.z'] as $field => $value) {
            $p = $this->assertProblem($this->addBatch($key, $job, [self::recipient(0, [$field => $value])]), 422, 'validation-error');
            self::assertSame("/recipients/0/$field", $p['errors'][0]['pointer'], $field);
        }
        $p = $this->assertProblem($this->addBatch($key, $job, [self::recipient(0, ['subject' => "Hi\r\nBcc: victim@example.com"])]), 422);
        self::assertSame('/recipients/0/subject', $p['errors'][0]['pointer']);
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM send_job_recipients WHERE send_job_id = ?', [$job]));
    }

    public function testUnsubscribeRulesByMessageClass(): void
    {
        [$client, $key] = $this->newApiClient();
        $domain = $this->verifiedDomain($client);
        $subscription = $this->createSendJob($key, $domain, 'subscription');
        $transactional = $this->createSendJob($key, $domain);
        $p = $this->assertProblem($this->addBatch($key, $subscription, [self::recipient(0)]), 422);
        self::assertSame('/recipients/0/unsubscribe_url', $p['errors'][0]['pointer']);
        $this->assertProblem($this->addBatch($key, $subscription, [self::recipient(0, ['unsubscribe_url' => 'http://u.example/x'])]), 422);
        $this->assertProblem($this->addBatch($key, $subscription, [self::recipient(0, ['unsubscribe_url' => 'not a url'])]), 422);
        self::assertSame(201, $this->addBatch($key, $subscription, [self::recipient(0, ['unsubscribe_url' => 'https://u.example/one-click/abc'])])->getStatusCode());
        $p = $this->assertProblem($this->addBatch($key, $transactional, [self::recipient(0, ['unsubscribe_url' => 'https://u.example/x'])]), 422);
        self::assertSame('/recipients/0/unsubscribe_url', $p['errors'][0]['pointer']);
        self::assertSame(202, $this->api('POST', "/v1/send-jobs/$subscription/submit", $key)->getStatusCode());
    }

    public function testInvalidRecipientAddressesAreReportedWithPointers(): void
    {
        [$client, $key] = $this->newApiClient();
        $job = $this->createSendJob($key, $this->verifiedDomain($client));
        $p = $this->assertProblem($this->addBatch($key, $job, [self::recipient(0), self::recipient(1, ['email_address' => 'no-at-sign']),
            self::recipient(2, ['email_address' => 'x@localhost'])]), 422);
        self::assertSame(['/recipients/1/email_address', '/recipients/2/email_address'], array_column($p['errors'], 'pointer'));
    }

    public function testSubmitSealsTheJobAndRecipientsBecomeImmutable(): void
    {
        [$client, $key] = $this->newApiClient();
        $job = $this->createSendJob($key, $this->verifiedDomain($client));
        $this->assertProblem($this->api('POST', "/v1/send-jobs/$job/submit", $key), 422, 'empty-job');
        $this->addBatch($key, $job, self::recipients(0, 3));
        $snapshot = Db::owner()->fetchAllAssociative('SELECT * FROM send_job_recipients WHERE send_job_id = ? ORDER BY id', [$job]);

        $sealed = $this->assertContract($this->api('POST', "/v1/send-jobs/$job/submit", $key), 202, '/send-jobs/{id}/submit', 'post');
        self::assertSame('queued', $sealed['status']);
        self::assertNotNull($sealed['queued_at']);
        self::assertSame(3, $sealed['total_recipients']);

        $this->assertProblem($this->addBatch($key, $job, self::recipients(10, 1)), 409, 'job-not-collecting');
        self::assertSame($snapshot, Db::owner()->fetchAllAssociative('SELECT * FROM send_job_recipients WHERE send_job_id = ? ORDER BY id', [$job]));
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM messages WHERE send_job_id = ?', [$job]), 'messages are created by Go (Phase 4)');
        $messages = $this->assertContract($this->api('GET', "/v1/send-jobs/$job/messages", $key), 200, '/send-jobs/{id}/messages', 'get');
        self::assertSame([], $messages['data']);
        $read = $this->assertContract($this->api('GET', "/v1/send-jobs/$job", $key), 200, '/send-jobs/{id}', 'get');
        self::assertSame($sealed, $read);
    }

    public function testCancelledOrFailedJobsCannotBeSubmitted(): void
    {
        [$client, $key] = $this->newApiClient();
        $job = $this->createSendJob($key, $this->verifiedDomain($client));
        Db::owner()->executeStatement("UPDATE send_jobs SET status = 'cancelled' WHERE id = ?", [$job]);
        $this->assertProblem($this->api('POST', "/v1/send-jobs/$job/submit", $key), 409, 'job-not-submittable');
        $this->assertProblem($this->addBatch($key, $job, self::recipients(0, 1)), 409, 'job-not-collecting');
    }

    public function testSubmitRequiresAVerifiedDomain(): void
    {
        [$client, $key] = $this->newApiClient();
        $job = $this->createSendJob($key, $this->pendingDomain($client));
        $this->addBatch($key, $job, self::recipients(0, 1));
        $this->assertProblem($this->api('POST', "/v1/send-jobs/$job/submit", $key), 422, 'submit-rejected');
        self::assertSame('collecting', Db::owner()->fetchOne('SELECT status FROM send_jobs WHERE id = ?', [$job]));
    }

    public function testControlledLocalModeAllowsUnverifiedDomains(): void
    {
        $this->withEnv(['SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS' => 'true'], function (): void {
            [$client, $key] = $this->newApiClient();
            $job = $this->createSendJob($key, $this->pendingDomain($client));
            $this->addBatch($key, $job, self::recipients(0, 1));
            self::assertSame('queued', self::json($this->api('POST', "/v1/send-jobs/$job/submit", $key))['status']);
        });
    }

    public function testProductionAlsoRequiresActiveDkim(): void
    {
        [$client, $key] = $this->newApiClient();
        $domain = $this->verifiedDomain($client);
        $job = $this->createSendJob($key, $domain);
        $this->addBatch($key, $job, self::recipients(0, 1));
        $this->withEnv(['SMARTHOST_ENV' => 'production'], function () use ($job, $key, $domain): void {
            $p = $this->assertProblem($this->api('POST', "/v1/send-jobs/$job/submit", $key), 422, 'submit-rejected');
            self::assertStringContainsString('DKIM', json_encode($p));
            $this->container()->get(\App\Domain\SendingDomainService::class)->setDkim($this->reload($domain), \App\Enum\DkimStatus::Active, 'sel1', self::actor());
            self::assertSame(202, $this->api('POST', "/v1/send-jobs/$job/submit", $key)->getStatusCode());
        });
    }

    public function testNothingIsHandedToPostfix(): void
    {
        [$client, $key] = $this->newApiClient();
        $job = $this->createSendJob($key, $this->verifiedDomain($client));
        $this->addBatch($key, $job, self::recipients(0, 2));
        $this->api('POST', "/v1/send-jobs/$job/submit", $key);
        $row = Db::owner()->fetchAssociative('SELECT status, claimed_by, started_at, attempt_count FROM send_jobs WHERE id = ?', [$job]);
        self::assertSame(['status' => 'queued', 'claimed_by' => null, 'started_at' => null, 'attempt_count' => 0], $row);
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM messages WHERE send_job_id = ?', [$job]));
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM webhook_events WHERE subject_id = ?', [$job]));
    }
}
