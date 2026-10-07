<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditActor;
use App\Client\ClientLifecycle;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Enum\ClientOrigin;
use App\Enum\ClientStatus;
use App\Enum\PolicyAcceptanceSource;
use App\Tests\Support\DashboardTestCase;
use App\Tests\Support\Db;

/**
 * Phase 9 client lifecycle (specification 2.10 client_lifecycle): explicit transitions
 * with a reason and the transition's permission, the policy-acceptance approval rule
 * (the test environment has APP_ACCEPTABLE_USE_POLICY_VERSION=aup-test-1), lifecycle
 * timestamps, the effect of each status on the API, the audit trail, and the public
 * onboarding gate (off by default).
 */
final class ClientLifecycleTest extends DashboardTestCase
{
    private function lifecycle(): ClientLifecycle
    {
        return $this->container()->get(ClientLifecycle::class);
    }

    private function pendingClient(): Client
    {
        return $this->lifecycle()->create('Applicant '.bin2hex(random_bytes(3)), 'ops@applicant.example', 'standard',
            ClientStatus::PendingApproval, self::actor(), true, 'billing@applicant.example', 'abuse@applicant.example');
    }

    public function testApprovalWorkflowWithPolicyAcceptance(): void
    {
        $client = $this->pendingClient();
        $key = $this->newKey($client);
        $id = $client->getId()->toRfc4122();
        self::assertSame('operator', Db::owner()->fetchOne('SELECT origin FROM clients WHERE id = ?', [$id]));
        self::assertNull(Db::owner()->fetchOne('SELECT approved_at FROM clients WHERE id = ?', [$id]));

        // A pending client authenticates and reads, but creates no work.
        $this->assertProblem($this->api('POST', '/v1/validation-jobs', $key, ['addresses' => [['address' => 'a@example.com']]], ['Idempotency-Key' => self::key()]), 403, 'forbidden');

        // Approval needs the acceptance of the policy version in force.
        try {
            $this->lifecycle()->changeStatus($this->reload($client), ClientStatus::Active, self::actor(), 'reviewed');
            self::fail('approval without policy acceptance');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('aup-test-1', $e->getMessage());
        }
        try {
            $this->lifecycle()->recordPolicyAcceptance($this->reload($client), 'aup-old', PolicyAcceptanceSource::ClientDashboard, self::actor());
            self::fail('only the version in force can be accepted from the dashboard');
        } catch (DomainRuleViolation) {
        }
        $admin = $this->newUser(false, $client, \App\Enum\ClientMembershipRole::Admin);
        $this->lifecycle()->recordPolicyAcceptance($this->reload($client), 'aup-test-1', PolicyAcceptanceSource::ClientDashboard,
            AuditActor::user($this->reload($admin)), $this->reload($admin));
        $again = $this->lifecycle()->recordPolicyAcceptance($this->reload($client), 'aup-test-1', PolicyAcceptanceSource::ClientDashboard, self::actor());
        self::assertSame(1, (int) Db::owner()->fetchOne('SELECT count(*) FROM client_policy_acceptances WHERE client_id = ?', [$id]), 'accepting twice changes nothing');
        self::assertSame($admin->getId()->toRfc4122(), $again->getAcceptedBy()?->getId()->toRfc4122());

        $this->lifecycle()->changeStatus($this->reload($client), ClientStatus::Active, self::actor(), 'reviewed: documents and DNS checked');
        $row = Db::owner()->fetchAssociative('SELECT status, approved_at IS NOT NULL AS approved, status_changed_at > created_at AS changed FROM clients WHERE id = ?', [$id]);
        self::assertSame(['status' => 'active', 'approved' => true, 'changed' => true], $row);
        self::assertSame(202, $this->api('POST', '/v1/validation-jobs', $key, ['addresses' => [['address' => 'a@example.com']]], ['Idempotency-Key' => self::key()])->getStatusCode());

        $audit = Db::owner()->fetchAllAssociative("SELECT action, detail_json->>'note' AS note, detail_json->>'from' AS from_status FROM audit_log
            WHERE target_type = 'client' AND target_id = ? AND action IN ('client.created', 'client.policy_accepted', 'client.approved') ORDER BY occurred_at", [$id]);
        self::assertSame(['client.created', 'client.policy_accepted', 'client.approved'], array_column($audit, 'action'));
        self::assertSame(['reviewed: documents and DNS checked', 'pending_approval'], [$audit[2]['note'], $audit[2]['from_status']]);
    }

    public function testTransitionsAreExplicitAndAudited(): void
    {
        [$client, $key] = $this->newApiClient();
        $id = $client->getId()->toRfc4122();
        $change = fn (ClientStatus $to, string $note = 'operator decision') => $this->lifecycle()->changeStatus($this->reload($client), $to, self::actor(), $note);

        foreach (['', 'x'] as $note) {
            try {
                $change(ClientStatus::Suspended, $note);
                self::fail('a reason is required');
            } catch (DomainRuleViolation $e) {
                self::assertStringContainsString('reason', $e->getMessage());
            }
        }
        foreach ([ClientStatus::PendingApproval, ClientStatus::Active] as $impossible) {
            try {
                $change($impossible);
                self::fail("active -> {$impossible->value}");
            } catch (DomainRuleViolation) {
            }
        }
        $change(ClientStatus::Throttled, 'complaint spike: slow down');
        $throttledJob = $this->api('POST', '/v1/validation-jobs', $key, ['addresses' => [['address' => 'b@example.com']]], ['Idempotency-Key' => self::key()]);
        self::assertSame(202, $throttledJob->getStatusCode(), 'throttled clients still create work');
        $jobId = self::json($throttledJob)['id'];
        $change(ClientStatus::Suspended, 'complaints continued');
        $this->assertProblem($this->api('POST', '/v1/validation-jobs', $key, ['addresses' => [['address' => 'c@example.com']]], ['Idempotency-Key' => self::key()]), 403, 'forbidden');
        self::assertSame(200, $this->api('GET', "/v1/validation-jobs/$jobId", $key)->getStatusCode(), 'suspended clients still read');
        $change(ClientStatus::Active, 'list cleaned, reactivated');
        $change(ClientStatus::Closed, 'contract ended');
        $this->assertProblem($this->api('GET', '/v1/validation-jobs/01999999-0000-7000-8000-000000000000', $key), 401, 'unauthorized');
        foreach (ClientStatus::cases() as $to) {
            try {
                $change($to);
                self::fail('closed is final');
            } catch (DomainRuleViolation) {
            }
        }
        self::assertNotNull(Db::owner()->fetchOne('SELECT closed_at FROM clients WHERE id = ?', [$id]));
        self::assertSame(['client.throttled', 'client.suspended', 'client.reactivated', 'client.closed'], Db::owner()->fetchFirstColumn(
            "SELECT action FROM audit_log WHERE target_type = 'client' AND target_id = ? AND action LIKE 'client.%' AND action NOT IN ('client.created', 'client.approved') ORDER BY occurred_at", [$id]));
    }

    public function testTransitionPermissions(): void
    {
        $client = $this->pendingClient();
        $restrictOnly = $this->newUser(false, null, roleKey: $this->role(['PLATFORM.CLIENT.VIEW', 'PLATFORM.CLIENT.RESTRICT']));
        $operator = $this->reload($restrictOnly);
        $this->acl()->resolve($operator);
        $this->lifecycle()->setPolicyAcceptanceRequired($this->reload($client), false, self::actor(), 'internal client');
        try {
            $this->lifecycle()->changeStatus($this->reload($client), ClientStatus::Active, AuditActor::user($operator), 'approve', $operator);
            self::fail('RESTRICT cannot approve');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('PLATFORM.CLIENT.APPROVE', $e->getMessage());
        }
        $this->lifecycle()->changeStatus($this->reload($client), ClientStatus::Active, self::actor(), 'approved by an approver');
        $this->lifecycle()->changeStatus($this->reload($client), ClientStatus::Suspended, AuditActor::user($operator), 'kill switch', $operator);
        try {
            $this->lifecycle()->changeStatus($this->reload($client), ClientStatus::Active, AuditActor::user($operator), 'undo', $operator);
            self::fail('RESTRICT cannot reactivate a suspended client');
        } catch (DomainRuleViolation) {
        }
        $actor = Db::owner()->fetchAssociative("SELECT actor_type, actor_id FROM audit_log WHERE action = 'client.suspended' AND target_id = ?", [$client->getId()->toRfc4122()]);
        self::assertSame(['actor_type' => 'user', 'actor_id' => $operator->getId()->toRfc4122()], $actor);
    }

    public function testCreationRulesAndThePublicOnboardingGate(): void
    {
        try {
            $this->lifecycle()->create('Internal', 'ops@internal.example', 'internal', ClientStatus::Active, self::actor(), true);
            self::fail('an active client needs the policy, or an exemption');
        } catch (DomainRuleViolation) {
        }
        foreach ([ClientStatus::Throttled, ClientStatus::Suspended, ClientStatus::Closed] as $status) {
            try {
                $this->lifecycle()->create('X', 'ops@x.example', 'p', $status, self::actor());
                self::fail("created {$status->value}");
            } catch (DomainRuleViolation) {
            }
        }
        $internal = $this->lifecycle()->create('Internal', 'ops@internal.example', 'internal', ClientStatus::Active, self::actor(), false);
        self::assertNotNull($internal->getApprovedAt());
        self::assertSame(['client.created', 'client.approved'], Db::owner()->fetchFirstColumn(
            "SELECT action FROM audit_log WHERE target_id = ? ORDER BY occurred_at", [$internal->getId()->toRfc4122()]));

        // Disabled by default (APP_PUBLIC_ONBOARDING_ENABLED=false): no public channel creates a client.
        self::assertFalse($this->lifecycle()->publicOnboardingEnabled());
        try {
            $this->lifecycle()->apply('Public Co', 'ops@public.example');
            self::fail('the gate is closed');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('APP_PUBLIC_ONBOARDING_ENABLED', $e->getMessage());
        }
        // Enabled, an application is only a pending record without credentials.
        $open = new ClientLifecycle($this->container()->get('doctrine')->getManager(), $this->container()->get(\App\Audit\AuditLogger::class), true, 'aup-test-1');
        $application = $open->apply('Public Co', 'ops@public.example');
        self::assertSame([ClientStatus::PendingApproval, ClientOrigin::PublicApplication], [$application->getStatus(), $application->getOrigin()]);
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM api_keys WHERE client_id = ?', [$application->getId()->toRfc4122()]));
        self::assertSame(0, (int) Db::owner()->fetchOne('SELECT count(*) FROM client_memberships WHERE client_id = ?', [$application->getId()->toRfc4122()]));
    }

    /** @param list<string> $permissions */
    private function role(array $permissions): string
    {
        $key = 'P9_'.strtoupper(bin2hex(random_bytes(3)));
        $admin = $this->newUser(false, null, roleKey: 'ADMIN');
        $role = $this->acl()->createRole($key, 'Phase 9 test '.$key, '', $this->reload($admin));
        $this->acl()->setRolePermissions($this->acl()->roleByKey($key), $permissions, $this->reload($admin));

        return $key;
    }
}
