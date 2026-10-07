<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\Limits;
use App\Config\SafetyGuard;
use App\Config\SecretEnvVarProcessor;
use PHPUnit\Framework\TestCase;

/** Fail-closed configuration rules (environment.md rules 2 and 4) and contract ceilings. */
final class ConfigurationTest extends TestCase
{
    private array $saved = [];

    private function setEnv(string $name, ?string $value): void
    {
        $this->saved[$name] ??= [$_SERVER[$name] ?? null];
        if (null === $value) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        } else {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv("$name=$value");
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$value]) {
            $this->setEnv($name, $value);
        }
    }

    public function testUnverifiedDomainsAreForbiddenInProduction(): void
    {
        $this->setEnv('SMARTHOST_ENV', 'production');
        $this->setEnv('SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS', 'true');
        $this->expectExceptionMessage('forbidden with SMARTHOST_ENV=production');
        SafetyGuard::assertSafe();
    }

    /** Phase 8: the production process rules (debug kernel, https links, SSRF allowlist). */
    public function testProductionProcessRules(): void
    {
        $this->setEnv('SMARTHOST_ENV', 'production');
        $this->setEnv('SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS', 'false');
        $this->setEnv('SMARTHOST_PUBLIC_BASE_URL', 'https://mail.operator.example');
        $this->setEnv('APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS', null);
        foreach ([
            ['APP_ENV', 'dev', 'never runs the debug kernel'],
            ['SMARTHOST_PUBLIC_BASE_URL', 'http://mail.operator.example', 'must be https'],
            ['APP_WEBHOOK_ALLOWED_PRIVATE_HOSTS', 'webhook-receiver', 'must be empty in production'],
        ] as [$name, $bad, $message]) {
            $this->setEnv('APP_ENV', 'prod');
            $this->setEnv($name, $bad);
            try {
                SafetyGuard::assertSafe();
                self::fail("$name=$bad accepted in production");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
            $this->setEnv($name, 'APP_ENV' === $name ? 'prod' : ('SMARTHOST_PUBLIC_BASE_URL' === $name ? 'https://mail.operator.example' : null));
        }
        SafetyGuard::assertSafe();
        self::addToAssertionCount(1);
    }

    public function testUnknownEnvironmentIsRejected(): void
    {
        $this->setEnv('SMARTHOST_ENV', 'staging');
        $this->expectException(\RuntimeException::class);
        SafetyGuard::assertSafe();
    }

    public function testDevelopmentMayAllowUnverifiedDomains(): void
    {
        $this->setEnv('SMARTHOST_ENV', 'development');
        $this->setEnv('SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS', 'true');
        SafetyGuard::assertSafe();
        self::addToAssertionCount(1);
    }

    public function testSecretFileIndirection(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sec');
        file_put_contents($file, "from-file\n");
        $p = new SecretEnvVarProcessor();
        $this->setEnv('P2_TEST_SECRET', null);
        $this->setEnv('P2_TEST_SECRET_FILE', $file);
        self::assertSame('from-file', $p->getEnv('secret', 'P2_TEST_SECRET', static fn () => null));
        $this->setEnv('P2_TEST_SECRET', 'direct');
        try {
            $p->getEnv('secret', 'P2_TEST_SECRET', static fn () => null);
            self::fail('X and X_FILE together must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('set only one', $e->getMessage());
        }
        $this->setEnv('P2_TEST_SECRET_FILE', null);
        self::assertSame('direct', $p->getEnv('secret', 'P2_TEST_SECRET', static fn () => null));
        unlink($file);
    }

    public function testLimitsMayLowerButNotRaiseTheContract(): void
    {
        $l = new Limits(50000, 900, 99999999);
        self::assertSame(10000, $l->maxRecipientsPerJob);
        self::assertSame(500, $l->maxRecipientsPerBatch);
        self::assertSame(10485760, $l->maxRequestBytes);
        $l = new Limits(100, 10, 1000);
        self::assertSame([100, 10, 1000], [$l->maxRecipientsPerJob, $l->maxRecipientsPerBatch, $l->maxRequestBytes]);
        $this->expectException(\InvalidArgumentException::class);
        new Limits(0, 1, 1);
    }
}
