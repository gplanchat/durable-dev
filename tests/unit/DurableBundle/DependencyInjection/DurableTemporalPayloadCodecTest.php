<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The application declares its payload codec as a service and names it (DUR055): Durable reads no
 * key, it hands the service to the client factory, which wraps the client with it.
 */
final class DurableTemporalPayloadCodecTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=default';

    public function testTheNamedCodecReachesTheClientFactory(): void
    {
        $arguments = $this->clientArguments(['dsn' => self::DSN, 'payload_codec' => 'app.codec']);

        self::assertEquals(new Reference('app.codec'), $arguments[4] ?? null);
        self::assertNull($arguments[2], 'no Guzzle client: its slot stays empty');
        self::assertNull($arguments[3], 'no PSR-18 client: its slot stays empty');
    }

    public function testTheCodecKeepsTheGuzzleClientInPlace(): void
    {
        $arguments = $this->clientArguments(['dsn' => self::DSN, 'guzzle_client' => 'app.guzzle', 'payload_codec' => 'app.codec']);

        self::assertEquals(new Reference('app.guzzle'), $arguments[2]);
        self::assertEquals(new Reference('app.codec'), $arguments[4]);
    }

    public function testWithoutACodecTheClientIsNotWrapped(): void
    {
        self::assertArrayNotHasKey(4, $this->clientArguments(['dsn' => self::DSN]));
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
