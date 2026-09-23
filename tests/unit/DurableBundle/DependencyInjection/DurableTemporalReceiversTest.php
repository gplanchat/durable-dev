<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Bridge\Temporal\Messenger\TemporalActivityWorkerTransport;
use Gplanchat\Bridge\Temporal\Messenger\TemporalJournalTransport;
use Gplanchat\Bridge\Temporal\Messenger\TemporalNexusWorkerTransport;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The Temporal workers are the bundle's, not the application's `messenger.yaml`.
 *
 * One server, one DSN: `durable.temporal.dsn`. The bundle turns it into receivers that
 * `messenger:consume` finds by alias, so the application declares no Temporal transport and picks
 * no worker kind in a DSN.
 *
 * @see https://github.com/gplanchat/durable-dev/issues/420
 */
final class DurableTemporalReceiversTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=default&tls=0';

    public function testTheWorkflowAndActivityWorkersAreConsumableUnderTheBackendIndependentNames(): void
    {
        $container = $this->load(['temporal' => ['dsn' => self::DSN]]);

        self::assertSame(
            ['durable_workflows' => TemporalJournalTransport::class, 'durable_activities' => TemporalActivityWorkerTransport::class],
            self::receivers($container),
        );
    }

    public function testTheNexusWorkerIsBuiltButNotConsumableUntilAHandlerIsDeclared(): void
    {
        // NexusHandlerPass is the one that knows whether a handler exists; it lays the tag.
        $container = $this->load(['temporal' => ['dsn' => self::DSN]]);

        self::assertSame(
            TemporalNexusWorkerTransport::class,
            $container->getDefinition('durable.temporal.nexus_receiver')->getClass(),
        );
        self::assertArrayNotHasKey('durable_nexus', self::receivers($container));
    }

    public function testWithoutTheJournalTheApplicationKeepsItsOwnWorkflowAndActivityTransports(): void
    {
        // `journal: false` runs workflows locally: the application's Messenger transports carry
        // them, and a Temporal receiver under the same name would steal the alias.
        $container = $this->load([
            'event_store' => ['type' => 'dbal'],
            'workflow_metadata' => ['type' => 'dbal'],
            'temporal' => ['dsn' => self::DSN, 'journal' => false],
        ]);

        self::assertSame([], self::receivers($container));
        self::assertTrue($container->hasDefinition('durable.temporal.nexus_receiver'));
    }

    public function testWithoutADsnThereIsNoTemporalReceiver(): void
    {
        $container = $this->load([]);

        self::assertSame([], self::receivers($container));
        self::assertFalse($container->hasDefinition('durable.temporal.nexus_receiver'));
    }

    /**
     * @return array<string, class-string|null> alias => receiver class
     */
    private static function receivers(ContainerBuilder $container): array
    {
        $receivers = [];
        foreach ($container->findTaggedServiceIds('messenger.receiver') as $id => $tags) {
            foreach ($tags as $tag) {
                $receivers[$tag['alias']] = $container->getDefinition($id)->getClass();
            }
        }

        return $receivers;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new DurableExtension())->load([$config], $container);

        return $container;
    }
}
