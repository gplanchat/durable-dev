<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowUpdateHandler;
use Gplanchat\Durable\Bundle\Transport\MessengerWorkflowTimerDispatcher;
use Gplanchat\Durable\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use unit\Bridge\Temporal\RecordingWorkflowClient;

/**
 * On Temporal native, signals and updates sent through Messenger reach the cluster; the journal
 * handlers and the Messenger timers, which nothing replays or calls there, are not registered (#333).
 *
 * @internal
 */
final class DurableWorkflowControlWiringTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=default&tls=0';

    public function testOnTemporalNativeASignalAndAnUpdateDispatchedOnTheBusReachTheCluster(): void
    {
        $client = new RecordingWorkflowClient();
        $bus = $this->busOf($this->load(['temporal' => ['dsn' => self::DSN]]), $client);

        $bus->dispatch(new DeliverWorkflowSignalMessage('exec-1', 'approve', ['by' => 'alice']));
        $bus->dispatch(new DeliverWorkflowUpdateMessage('exec-1', 'setDiscount', ['percent' => 10]));

        self::assertSame([
            ['signal', 'wf-exec-1', 'approve', ['by' => 'alice']],
            ['update', 'wf-exec-1', 'setDiscount', ['percent' => 10]],
        ], $client->calls);
    }

    public function testOnTemporalNativeTheJournalHandlersAndTheMessengerTimersAreNotRegistered(): void
    {
        $container = $this->load(['temporal' => ['dsn' => self::DSN]]);

        foreach ([DeliverWorkflowSignalHandler::class, DeliverWorkflowUpdateHandler::class, FireWorkflowTimersHandler::class, MessengerWorkflowTimerDispatcher::class, WorkflowTimerDispatcher::class] as $id) {
            self::assertFalse($container->has($id), $id);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function journalConfigurations(): iterable
    {
        yield 'no DSN' => [[]];
        yield 'DSN without the journal' => [[
            'event_store' => ['type' => 'dbal'],
            'workflow_metadata' => ['type' => 'dbal'],
            'temporal' => ['dsn' => self::DSN, 'journal' => false],
        ]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('journalConfigurations')]
    public function testOnTheJournalTheJournalHandlersAndTheMessengerTimersStay(array $config): void
    {
        $container = $this->load($config);

        foreach ([DeliverWorkflowSignalHandler::class, DeliverWorkflowUpdateHandler::class, FireWorkflowTimersHandler::class, MessengerWorkflowTimerDispatcher::class, WorkflowTimerDispatcher::class] as $id) {
            self::assertTrue($container->has($id), $id);
        }
    }

    /**
     * A real bus over the container's handlers: the one Messenger would build from the tag.
     */
    private function busOf(ContainerBuilder $container, WorkflowClientInterface $client): MessageBus
    {
        $container->register(WorkflowClientInterface::class)->setSynthetic(true)->setPublic(true);
        $handlerIds = array_keys($container->findTaggedServiceIds('messenger.message_handler'));
        foreach ($handlerIds as $id) {
            $container->getDefinition($id)->setPublic(true);
        }
        $container->compile();
        $container->set(WorkflowClientInterface::class, $client);

        $handlers = [];
        foreach ($handlerIds as $id) {
            $handler = $container->get($id);
            $type = (new \ReflectionMethod($handler, '__invoke'))->getParameters()[0]->getType();
            \assert($type instanceof \ReflectionNamedType);
            $handlers[$type->getName()][] = $handler;
        }

        return new MessageBus([new HandleMessageMiddleware(new HandlersLocator($handlers))]);
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
