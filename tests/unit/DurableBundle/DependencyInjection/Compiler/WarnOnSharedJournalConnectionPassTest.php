<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\WarnOnSharedJournalConnectionPass;
use Gplanchat\Durable\Bundle\EventListener\WarnOnSharedJournalConnectionListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * DUR054, decision 6: a DBAL journal on the application's default connection is found at compile
 * time and warned about when a worker starts. Never refused.
 */
final class WarnOnSharedJournalConnectionPassTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function sharedConnections(): iterable
    {
        yield 'the default alias' => ['doctrine.dbal.default_connection'];
        yield 'the default connection, by its own name' => ['doctrine.dbal.app_connection'];
    }

    #[DataProvider('sharedConnections')]
    public function testAJournalOnTheDefaultConnectionGetsTheWarning(string $journal): void
    {
        $container = $this->container($journal);

        (new WarnOnSharedJournalConnectionPass())->process($container);

        self::assertTrue($container->hasDefinition(WarnOnSharedJournalConnectionListener::class));
        $listener = $container->getDefinition(WarnOnSharedJournalConnectionListener::class);
        // Registered after ResolveClassPass: the class is not inferred from the id any more, and a
        // definition without one fails CheckDefinitionValidityPass in a real compile.
        self::assertSame(WarnOnSharedJournalConnectionListener::class, $listener->getClass());
        self::assertSame([['event' => WorkerStartedEvent::class]], $listener->getTag('kernel.event_listener'));
        self::assertSame($journal, $listener->getArgument(1));
    }

    public function testAJournalOnAConnectionOfItsOwnDoesNot(): void
    {
        $container = $this->container('doctrine.dbal.durable_connection');

        (new WarnOnSharedJournalConnectionPass())->process($container);

        self::assertFalse($container->hasDefinition(WarnOnSharedJournalConnectionListener::class));
    }

    public function testWithoutADbalJournalNothingIsChecked(): void
    {
        $container = new ContainerBuilder();

        (new WarnOnSharedJournalConnectionPass())->process($container);

        self::assertFalse($container->hasDefinition(WarnOnSharedJournalConnectionListener::class));
    }

    public function testWithoutALoggerThereIsNothingToWarnThrough(): void
    {
        $container = $this->container('doctrine.dbal.default_connection');
        $container->removeDefinition('logger');

        (new WarnOnSharedJournalConnectionPass())->process($container);

        self::assertFalse($container->hasDefinition(WarnOnSharedJournalConnectionListener::class));
    }

    public function testTheContainerCompilesWithTheWarningRegistered(): void
    {
        $container = $this->container('doctrine.dbal.default_connection');
        foreach (['logger', 'doctrine.dbal.app_connection', 'doctrine.dbal.durable_connection', 'durable.dbal.schema'] as $id) {
            $container->getDefinition($id)->setPublic(true);
        }
        $container->addCompilerPass(new WarnOnSharedJournalConnectionPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 20);
        // Kept public so that the removing passes, with no RegisterListenersPass here, leave it be.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(WarnOnSharedJournalConnectionListener::class)->setPublic(true);
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);

        $container->compile();

        self::assertInstanceOf(WarnOnSharedJournalConnectionListener::class, $container->get(WarnOnSharedJournalConnectionListener::class));
    }

    private function container(string $journal): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition('logger', new Definition(NullLogger::class));
        // What DoctrineBundle registers for `default_connection: app` and a second `durable` one.
        $container->setDefinition('doctrine.dbal.app_connection', new Definition(\stdClass::class));
        $container->setDefinition('doctrine.dbal.durable_connection', new Definition(\stdClass::class));
        $container->setAlias('doctrine.dbal.default_connection', 'doctrine.dbal.app_connection');
        $container->setDefinition('durable.dbal.schema', new Definition(\stdClass::class, [new Reference($journal)]));

        return $container;
    }
}
