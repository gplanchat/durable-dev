<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Bundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * @internal
 *
 * @coversNothing
 */
final class ConfigurationTemporalEventStoreTest extends TestCase
{
    /**
     * @test
     */
    public function eventStoreTemporalAcceptsDsn(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [[
            'event_store' => [
                'type' => 'temporal',
                'temporal' => ['dsn' => 'temporal://127.0.0.1:7233?namespace=default'],
            ],
        ]]);

        self::assertSame('temporal', $config['event_store']['type']);
        self::assertSame('temporal://127.0.0.1:7233?namespace=default', $config['event_store']['temporal']['dsn']);
    }
}
