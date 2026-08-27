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
 * Handler « livraison » d'un signal : même contrat qu’avec Messenger en prod, reprise pilotée par callback.
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

        // `waitSignal()` n'existe plus : un signal enregistre un handler qui mute l'état, et
        // une condition passée à `await()` observe cet état. Le handler est réenregistré à chaque
        // passe, y compris au replay — c'est ce qui reconstruit `$payload` à la reprise.
        //
        // La condition est une closure avec `use (&$payload)`, **pas** une fonction fléchée : une
        // fléchée capture par valeur au moment où elle est écrite, donc elle observerait à jamais
        // le `null` initial. Le handler muterait bien la variable et l'attente ne finirait jamais.
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
                self::assertFalse($stillSuspended, 'le workflow doit terminer après réception du signal');
            },
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

}
