<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Bridge\Temporal\Http\Psr18Http;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * transport=http goes through curl unless it is handed the application's PSR-18 client.
 */
final class DurableTemporalPsr18ClientTest extends TestCase
{
    private const DSN = 'temporal+http://127.0.0.1?namespace=default';

    public function testTheClientAndItsFactoryReachTheClientFactory(): void
    {
        $gateway = $this->clientArguments(['dsn' => self::DSN, 'psr18_client' => 'app.psr18', 'psr17_factory' => 'app.psr17'])[3] ?? null;

        self::assertInstanceOf(Definition::class, $gateway);
        self::assertSame(Psr18Http::class, $gateway->getClass());
        self::assertEquals([new Reference('app.psr18'), new Reference('app.psr17'), new Reference('app.psr17')], $gateway->getArguments());
    }

    public function testTheFactoryDefaultsToTheClientAsSymfonysPsr18ClientIsBoth(): void
    {
        $arguments = $this->clientArguments(['dsn' => self::DSN, 'psr18_client' => 'psr18.http_client']);

        self::assertNull($arguments[2], 'no Guzzle client: its slot stays empty');
        self::assertEquals(array_fill(0, 3, new Reference('psr18.http_client')), $arguments[3]->getArguments());
    }

    public function testNothingIsHandedByDefault(): void
    {
        self::assertArrayNotHasKey(3, $this->clientArguments(['dsn' => self::DSN]));
    }

    /**
     * @param array<string, mixed> $temporal
     *
     * @return array<int|string, mixed>
     */
    private function clientArguments(array $temporal): array
    {
        $container = new ContainerBuilder();
        (new DurableExtension())->load([['temporal' => $temporal]], $container);

        return $container->getDefinition('durable.temporal.workflow_service_client')->getArguments();
    }
}
