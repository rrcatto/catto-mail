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
