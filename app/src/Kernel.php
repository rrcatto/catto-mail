<?php

declare(strict_types=1);

namespace App;

use App\Config\SafetyGuard;
use App\Util\InstallationTime;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Fail closed before any request or command runs (environment contract rule 4).
        SafetyGuard::assertSafe();
        // Every time the application computes, stores or writes is in the installation's zone.
        InstallationTime::applyProcessDefault();
        parent::boot();
    }

    /**
     * @return list<string>
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
