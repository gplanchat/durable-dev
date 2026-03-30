<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Bundle;

use Gplanchat\Durable\Bundle\DurableBundle;
use Gplanchat\Durable\Bundle\Handler\ActivityRunHandler;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Mode distribué + transport Messenger activités : {@see ActivityRunHandler} via
 * {@see ReceivedStamp} sur le transport configuré (équivalent local de messenger:consume).
 *
 * @internal
 */
#[CoversClass(ActivityRunHandler::class)]
final class DurableActivityRunHandlerIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DurableActivityMessengerTestKernel::class;
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        self::$class = null;
        self::$kernel = null;
        self::$booted = false;
        restore_exception_handler();
    }

    #[Test]
    public function activityRunHandlerExecutesActivityAndAppendsCompletion(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has(ActivityRunHandler::class), 'ActivityRunHandler en mode distributed + messenger');

        $container->get(\Gplanchat\Durable\ActivityExecutor::class)->register(
            'echo',
            static fn (array $p): string => (string) ($p['v'] ?? ''),
        );

        $executionId = '01900000-0000-7000-8000-0000000000c1';
        $message = new ActivityMessage($executionId, 'act-1', 'echo', ['v' => 'done'], []);

        $bus = $container->get(MessageBusInterface::class);
        $bus->dispatch(new Envelope($message, [new ReceivedStamp('durable_activities')]));

        $store = $container->get(EventStoreInterface::class);
        $completed = null;
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof ActivityCompleted && 'act-1' === $event->activityId()) {
                $completed = $event;
                break;
            }
        }

        self::assertInstanceOf(ActivityCompleted::class, $completed);
        self::assertSame('done', $completed->result());
    }
}

final class DurableActivityMessengerTestKernel extends \Symfony\Component\HttpKernel\Kernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DurableBundle(),
        ];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
        $loader->load(__DIR__.'/config/durable_distributed_messenger_activities.php');
    }
}
