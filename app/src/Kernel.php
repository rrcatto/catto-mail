<?php

declare(strict_types=1);

namespace App;

use App\Config\SafetyGuard;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Fail closed before any request or command runs (environment contract rule 4).
        SafetyGuard::assertSafe();
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
