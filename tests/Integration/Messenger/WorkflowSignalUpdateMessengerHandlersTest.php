<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Integration\Messenger;

use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowUpdateHandler;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Tests\Support\CallbackWorkflowResumeDispatcher;
use Gplanchat\Durable\Tests\Support\StepwiseWorkflowHarness;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Handlers « livraison » signal/update : même contrat qu’avec Messenger en prod, reprise pilotée par callback.
 *
 * @internal
 */
#[CoversClass(DeliverWorkflowSignalHandler::class)]
#[CoversClass(DeliverWorkflowUpdateHandler::class)]
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

        $workflow = static function (WorkflowEnvironment $env) {
            return $env->waitSignal('go');
        };

        $resumeCount = 0;
        $dispatcher = new CallbackWorkflowResumeDispatcher(
            function (string $id) use (&$resumeCount, $harness, $workflow, $executionId): void {
                ++$resumeCount;
                self::assertSame($executionId, $id);
                $stillSuspended = $harness->resume($id, $workflow);
                self::assertFalse($stillSuspended, 'le workflow doit terminer après réception du signal');
            }
        );

        $handler = new DeliverWorkflowSignalHandler($eventStore, $dispatcher);

        self::assertTrue($harness->start($executionId, $workflow), 'suspendu en attente du signal');

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

    #[Test]
    public function deliverUpdateAppendsToJournalAndResumeCompletesWorkflow(): void
    {
        $eventStore = new InMemoryEventStore();
        $activityTransport = new InMemoryActivityTransport();
        $activityExecutor = new RegistryActivityExecutor();
        $harness = StepwiseWorkflowHarness::create($eventStore, $activityTransport, $activityExecutor);
        $executionId = (string) Uuid::v7();

        $workflow = static function (WorkflowEnvironment $env) {
            return $env->waitUpdate('reserveStock');
        };

        $dispatcher = new CallbackWorkflowResumeDispatcher(
            function (string $id) use ($harness, $workflow, $executionId): void {
                self::assertSame($executionId, $id);
                self::assertFalse($harness->resume($id, $workflow));
            }
        );

        $handler = new DeliverWorkflowUpdateHandler($eventStore, $dispatcher);

        self::assertTrue($harness->start($executionId, $workflow));

        $handler->__invoke(new DeliverWorkflowUpdateMessage($executionId, 'reserveStock', ['sku' => 'x', 'qty' => 3], ['reserved' => true]));

        self::assertSame(['reserved' => true], $harness->lastCompletedResult());

        $updates = [];
        foreach ($eventStore->readStream($executionId) as $e) {
            if ($e instanceof WorkflowUpdateHandled) {
                $updates[] = $e->updateName();
            }
        }
        self::assertSame(['reserveStock'], $updates);
    }
}
