<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Integration\Messenger;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\MessengerActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
#[CoversClass(MessengerActivityTransport::class)]
final class MessengerActivityTransportTest extends TestCase
{
    #[Test]
    public function scheduleAndCompleteViaMessenger(): void
    {
        $eventStore = new InMemoryEventStore();
        $symfonyTransport = new InMemoryTransport();
        $activityTransport = new MessengerActivityTransport($symfonyTransport, $symfonyTransport);
        $activityExecutor = new RegistryActivityExecutor();
        $activityExecutor->register('echo', fn (array $p) => $p['msg'] ?? 'ok');

        $runtime = new ExecutionRuntime($eventStore, $activityTransport, $activityExecutor);
        $engine = new ExecutionEngine($eventStore, $runtime);
        $executionId = (string) Uuid::v7();

        $result = $engine->start($executionId, function (WorkflowEnvironment $env) {
            return $env->await($env->activity('echo', ['msg' => 'hello messenger']));
        });

        self::assertSame('hello messenger', $result);

        $events = iterator_to_array($eventStore->readStream($executionId));
        self::assertCount(4, $events);
        self::assertInstanceOf(ExecutionStarted::class, $events[0]);
        self::assertInstanceOf(ActivityScheduled::class, $events[1]);
        self::assertInstanceOf(ActivityCompleted::class, $events[2]);
        self::assertInstanceOf(ExecutionCompleted::class, $events[3]);
    }
}
