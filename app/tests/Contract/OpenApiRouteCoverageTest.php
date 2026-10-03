<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Api\OpenApiContract;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every /v1 route is an OpenAPI operation and every OpenAPI operation has a route:
 * no undocumented /v1 endpoints and no missing ones.
 */
final class OpenApiRouteCoverageTest extends KernelTestCase
{
    public function testRoutesEqualOperations(): void
    {
        /** @var OpenApiContract $contract */
        $contract = static::getContainer()->get(OpenApiContract::class);
        $operations = [];
        foreach ($contract->document()['paths'] as $path => $item) {
            foreach (array_keys($item) as $method) {
                if (\in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $operations[] = strtoupper($method).' /v1'.$path;
                }
            }
        }
        $routes = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection() as $route) {
            if (str_starts_with($route->getPath(), '/v1')) {
                foreach ($route->getMethods() as $method) {
                    $routes[] = $method.' '.$route->getPath();
                }
            }
        }
        sort($operations);
        sort($routes);
        self::assertSame($operations, $routes);
    }

    public function testContractEnumsMatchPhpEnums(): void
    {
        $schemas = static::getContainer()->get(OpenApiContract::class)->document()['components']['schemas'];
        $map = ['ValidationJobStatus' => \App\Enum\ValidationJobStatus::class, 'SendJobStatus' => \App\Enum\SendJobStatus::class,
            'MessageClass' => \App\Enum\MessageClass::class, 'MessageStatus' => \App\Enum\MessageStatus::class,
            'MessageEventType' => \App\Enum\MessageEventType::class, 'WebhookEventType' => \App\Enum\WebhookEventType::class,
            'OverallClassification' => \App\Enum\OverallClassification::class, 'TypoReasonCode' => \App\Enum\TypoReasonCode::class,
            'SyntaxStatus' => \App\Enum\SyntaxStatus::class, 'DomainStatus' => \App\Enum\DomainStatus::class,
            'SmtpStatus' => \App\Enum\SmtpStatus::class, 'ConfidenceLevel' => \App\Enum\ConfidenceLevel::class];
        foreach ($map as $schema => $enum) {
            self::assertSame($schemas[$schema]['enum'], array_column($enum::cases(), 'value'), $schema);
        }
    }
}
