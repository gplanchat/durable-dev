<?php

declare(strict_types=1);

namespace integration\Durable\Messenger;

use Gplanchat\Durable\Bundle\Transport\MessengerActivityTransport;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskCompleted;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use unit\Durable\Fixtures\SuiteActivities;

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
        $activityExecutor->register('echo', fn(array $p) => $p['v'] ?? 'ok');

        $runtime = new ExecutionRuntime($eventStore, $activityTransport, $activityExecutor);
        $engine = new ExecutionEngine($eventStore, $runtime);
        $executionId = (string) Uuid::v7();

        $result = $engine->start($executionId, function (WorkflowEnvironment $env) {
            return $env->await($env->activityStub(SuiteActivities::class)->echoValue('hello messenger'));
        });

        self::assertSame('hello messenger', $result);

        $events = iterator_to_array($eventStore->readStream($executionId));
        // The journal held four of them when this test was written. `ActivityTaskStarted` and
        // `ActivityTaskCompleted` have been added since: the execution attempt is now recorded
        // apart from the outcome of the activity, which is what makes it possible to tell a
        // retry from a first try. The sequence is written out in full rather than counted, so
        // that one extra event shows itself rather than knocking a number over.
        // Without these two lines, the test is green with **any** transport: measured, by
        // replacing `MessengerActivityTransport` with `InMemoryActivityTransport`. It carried a
        // `#[CoversClass]` it did not honour — what it observed was the engine journal, not the
        // trip through Messenger. What sets this transport apart from the others is that an
        // envelope leaves on the Symfony transport and is acknowledged there.
        self::assertCount(1, $symfonyTransport->getSent(), 'one envelope leaves on the Symfony transport');
        self::assertCount(1, $symfonyTransport->getAcknowledged(), 'and it is acknowledged there');

        self::assertSame([
            ExecutionStarted::class,
            ActivityScheduled::class,
            ActivityTaskStarted::class,
            ActivityTaskCompleted::class,
            ActivityCompleted::class,
            ExecutionCompleted::class,
        ], array_map(static fn(object $e): string => $e::class, $events));
    }
}
