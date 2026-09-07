<?php

declare(strict_types=1);

namespace integration\Durable\Messenger;

use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use integration\Durable\Support\CallbackWorkflowResumeDispatcher;
use integration\Durable\Support\StepwiseWorkflowHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Signal "delivery" handler: same contract as with Messenger in production, resume driven by a callback.
 *
 * @internal
 */
#[CoversClass(DeliverWorkflowSignalHandler::class)]
final class WorkflowSignalUpdateMessengerHandlersTest extends TestCase
{
    #[Test]
    public function deliverSignalAppendsToJournalAndResumeCompletesWorkflow(): void
    {
        $eventStore = new InMemoryEventStore();
        $activityTransport = new InMemoryActivityTransport();
        $activityExecutor = new RegistryActivityExecutor();
        $harness = StepwiseWorkflowHarness::create($eventStore, $activityTransport, $activityExecutor);
        $executionId = (string) Uuid::v7();

        // `waitSignal()` no longer exists: a signal registers a handler that mutates the state,
        // and a condition passed to `await()` observes that state. The handler is re-registered on
        // every pass, replay included — that is what rebuilds `$payload` on resume.
        //
        // The condition is a closure with `use (&$payload)`, **not** an arrow function: an arrow
        // function captures by value at the point where it is written, so it would forever observe
        // the initial `null`. The handler would indeed mutate the variable and the wait would
        // never end.
        $workflow = static function (WorkflowEnvironment $env) {
            $payload = null;
            $env->onSignal('go', static function (array $received) use (&$payload): void {
                $payload = $received;
            });
            $env->await(static function () use (&$payload): bool {
                return null !== $payload;
            });

            return $payload;
        };

        $resumeCount = 0;
        $dispatcher = new CallbackWorkflowResumeDispatcher(
            function (string $id) use (&$resumeCount, $harness, $workflow, $executionId): void {
                ++$resumeCount;
                self::assertSame($executionId, $id);
                $stillSuspended = $harness->resume($id, $workflow);
                self::assertFalse($stillSuspended, 'the workflow must finish once the signal is received');
            },
        );

        $handler = new DeliverWorkflowSignalHandler($eventStore, $dispatcher);

        self::assertTrue($harness->start($executionId, $workflow), 'suspended waiting for the signal');

        $handler->__invoke(new DeliverWorkflowSignalMessage($executionId, 'go', ['ticket' => 'A-12']));

        self::assertSame(1, $resumeCount);
        self::assertSame(['ticket' => 'A-12'], $harness->lastCompletedResult());

        $signals = [];
        foreach ($eventStore->readStream($executionId) as $e) {
            if ($e instanceof WorkflowSignalReceived) {
                $signals[] = $e->signalName();
            }
        }
        self::assertSame(['go'], $signals);
    }

}
