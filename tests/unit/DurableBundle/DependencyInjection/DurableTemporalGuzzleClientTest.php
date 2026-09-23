<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * transport=guzzle builds a default Guzzle client unless it is handed one: the application's,
 * with its proxy, its TLS options and its middleware.
 */
final class DurableTemporalGuzzleClientTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=default&transport=guzzle';

    public function testTheConfiguredServiceReachesTheClientFactory(): void
    {
        $arguments = $this->clientArguments(['dsn' => self::DSN, 'guzzle_client' => 'app.guzzle']);

        self::assertEquals(new Reference('app.guzzle'), $arguments[2] ?? null);
    }

    public function testNothingIsHandedByDefault(): void
    {
        self::assertArrayNotHasKey(2, $this->clientArguments(['dsn' => self::DSN]));
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
