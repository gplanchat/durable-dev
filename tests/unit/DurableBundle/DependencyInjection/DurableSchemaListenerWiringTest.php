<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\SchemaListener\DurableSchemaListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Without its tag the listener is never called, and `migrations:diff` generates the removal of the
 * journal's tables. Nothing asserted the tag (#339, R-13).
 */
final class DurableSchemaListenerWiringTest extends TestCase
{
    public function testTheListenerHearsPostGenerateSchema(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        (new DurableExtension())->load([['event_store' => ['type' => 'dbal']]], $container);

        $definition = $container->getDefinition('durable.dbal.schema_listener');

        self::assertSame(DurableSchemaListener::class, $definition->getClass());
        self::assertSame([['event' => 'postGenerateSchema']], $definition->getTag('doctrine.event_listener'));
    }
}
