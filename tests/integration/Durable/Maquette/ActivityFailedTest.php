<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Maquette;

use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\WorkflowEnvironment;
use integration\Gplanchat\Durable\Fixtures\DeclaredStockDepletedFailure;
use integration\Gplanchat\Durable\Fixtures\NonSerializableDeclaredFailure;
use integration\Gplanchat\Durable\Support\DurableTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
#[CoversClass(ExecutionEngine::class)]
#[CoversClass(ExecutionRuntime::class)]
final class ActivityFailedTest extends DurableTestCase
{
    #[Test]
    public function activityFailureAppendsActivityFailedAndPropagatesToAwait(): void
    {
        $stack = $this->stack();
        $eventStore = $stack['eventStore'];
        $activityExecutor = $stack['activityExecutor'];
        $runtime = $stack['runtime'];
        $executionId = $this->executionId();

        $activityExecutor->register('fail', fn () => throw new \RuntimeException('Activity failed'));

        $engine = new ExecutionEngine($eventStore, $runtime);

        try {
            $engine->start($executionId, function (WorkflowEnvironment $env) {
                return $env->await($env->activity('fail', []));
            });
            self::fail('Expected DurableWorkflowAlgorithmFailureException');
        } catch (DurableWorkflowAlgorithmFailureException $e) {
            self::assertStringContainsString('Workflow did not handle activity failure', $e->getMessage());
            self::assertInstanceOf(DurableActivityFailedException::class, $e->getPrevious());
            self::assertStringContainsString('Activity failed', $e->getPrevious()->getMessage());
        }
    }

    #[Test]
    public function activityFailureIsPersistedInEventStore(): void
    {
        $stack = $this->stack();
        $eventStore = $stack['eventStore'];
        $activityExecutor = $stack['activityExecutor'];
        $runtime = $stack['runtime'];
        $executionId = $this->executionId();

        $activityExecutor->register('fail', fn () => throw new \DomainException('Domain error', 42));

        $engine = new ExecutionEngine($eventStore, $runtime);

        try {
            $engine->start($executionId, function (WorkflowEnvironment $env) {
                return $env->await($env->activity('fail', []));
            });
        } catch (DurableWorkflowAlgorithmFailureException) {
            // ADR018: échec d’algorithme attendu — les assertions portent sur le journal ci-dessous.
        }

        $this->assertEventTypesOrder(
            $executionId,
            ExecutionStarted::class,
            ActivityScheduled::class,
            ActivityFailed::class,
            WorkflowExecutionFailed::class,
        );
    }

    #[Test]
    public function replayResolvesActivityFailedAsRejection(): void
    {
        $stack = $this->stack();
        $eventStore = $stack['eventStore'];
        $activityExecutor = $stack['activityExecutor'];
        $runtime = $stack['runtime'];
        $executionId = $this->executionId();

        $activityExecutor->register('fail', fn () => throw new \RuntimeException('Original failure'));

        $engine = new ExecutionEngine($eventStore, $runtime);

        try {
            $engine->start($executionId, function (WorkflowEnvironment $env) {
                return $env->await($env->activity('fail', []));
            });
        } catch (DurableWorkflowAlgorithmFailureException) {
            // ADR018: attendu avant replay — le journal contient ActivityFailed pour rejouer await().
        }

        $newContext = new ExecutionContext($executionId, $eventStore, $runtime->getActivityTransport(), null);
        $replayAwaitable = $newContext->activity('fail', []);
        self::assertTrue($replayAwaitable->isSettled());

        $this->expectException(DurableActivityFailedException::class);
        $this->expectExceptionMessage('Original failure');
        $replayAwaitable->getResult();
    }

    #[Test]
    public function handledActivityFailureDoesNotRecordWorkflowExecutionFailed(): void
    {
        $stack = $this->stack();
        $eventStore = $stack['eventStore'];
        $activityExecutor = $stack['activityExecutor'];
        $runtime = $stack['runtime'];
        $executionId = $this->executionId();

        $activityExecutor->register('fail', fn () => throw new \RuntimeException('boom'));

        $engine = new ExecutionEngine($eventStore, $runtime);

        $result = $engine->start($executionId, function (WorkflowEnvironment $env) {
            try {
                return $env->await($env->activity('fail', []));
            } catch (DurableActivityFailedException) {
                return 'recovered';
            }
        });

        self::assertSame('recovered', $result);
        $this->assertEventTypesOrder(
            $executionId,
            ExecutionStarted::class,
            ActivityScheduled::class,
            ActivityFailed::class,
            ExecutionCompleted::class,
        );
    }

    #[Test]
    public function nonSerializableDeclaredFailureProducesCatastrophicEvent(): void
    {
        $stack = $this->stack();
        $eventStore = $stack['eventStore'];
        $activityExecutor = $stack['activityExecutor'];
        $runtime = $stack['runtime'];
        $executionId = $this->executionId();

        $activityExecutor->register('bad', fn () => throw new NonSerializableDeclaredFailure());

        $engine = new ExecutionEngine($eventStore, $runtime);

        try {
            $engine->start($executionId, function (WorkflowEnvironment $env) {
                return $env->await($env->activity('bad', []));
            });
            self::fail('Expected algorithm failure');
        } catch (DurableWorkflowAlgorithmFailureException $e) {
            self::assertInstanceOf(DurableCatastrophicActivityFailureException::class, $e->getPrevious());
        }

        $types = array_map(
            static fn (\Gplanchat\Durable\Event\Event $ev) => $ev::class,
            iterator_to_array($eventStore->readStream($executionId)),
        );
        self::assertContains(ActivityCatastrophicFailure::class, $types);
        self::assertNotContains(ActivityFailed::class, $types);
    }

    #[Test]
    public function declaredActivityFailureIsRestoredOnReplay(): void
    {
        $stack = $this->stack();
        $eventStore = $stack['eventStore'];
        $activityExecutor = $stack['activityExecutor'];
        $runtime = $stack['runtime'];
        $executionId = $this->executionId();

        $activityExecutor->register('reserve', fn () => throw new DeclaredStockDepletedFailure('SKU-42'));

        $engine = new ExecutionEngine($eventStore, $runtime);

        try {
            $engine->start($executionId, function (WorkflowEnvironment $env) {
                return $env->await($env->activity('reserve', []));
            });
            self::fail('Expected algorithm failure');
        } catch (DurableWorkflowAlgorithmFailureException $e) {
            self::assertInstanceOf(DeclaredStockDepletedFailure::class, $e->getPrevious());
        }

        $newContext = new ExecutionContext($executionId, $eventStore, $runtime->getActivityTransport(), null);
        $replayAwaitable = $newContext->activity('reserve', []);
        self::assertTrue($replayAwaitable->isSettled());

        try {
            $replayAwaitable->getResult();
            self::fail('Expected DeclaredStockDepletedFailure');
        } catch (DeclaredStockDepletedFailure $e) {
            self::assertSame('SKU-42', $e->sku());
        }
    }

    #[Test]
    public function retrySucceedsAfterTransientFailure(): void
    {
        $eventStore = new \Gplanchat\Durable\Store\InMemoryEventStore();
        $activityTransport = new \Gplanchat\Durable\Transport\InMemoryActivityTransport();
        $activityExecutor = new \Gplanchat\Durable\RegistryActivityExecutor();
        $runtime = new ExecutionRuntime($eventStore, $activityTransport, $activityExecutor, 2);

        $attempt = 0;
        $activityExecutor->register('flaky', function () use (&$attempt) {
            ++$attempt;
            if ($attempt < 2) {
                throw new \RuntimeException('Transient');
            }

            return 'ok';
        });

        $engine = new ExecutionEngine($eventStore, $runtime);
        $executionId = $this->executionId();

        $result = $engine->start($executionId, function (WorkflowEnvironment $env) {
            return $env->await($env->activity('flaky', []));
        });

        self::assertSame('ok', $result);
        self::assertSame(2, $attempt);
    }

    #[Test]
    public function retryExhaustedAppendsActivityFailed(): void
    {
        $eventStore = new \Gplanchat\Durable\Store\InMemoryEventStore();
        $activityTransport = new \Gplanchat\Durable\Transport\InMemoryActivityTransport();
        $activityExecutor = new \Gplanchat\Durable\RegistryActivityExecutor();
        $runtime = new ExecutionRuntime($eventStore, $activityTransport, $activityExecutor, 1);

        $activityExecutor->register('always_fail', fn () => throw new \RuntimeException('Always fails'));

        $engine = new ExecutionEngine($eventStore, $runtime);
        $executionId = $this->executionId();

        try {
            $engine->start($executionId, function (WorkflowEnvironment $env) {
                return $env->await($env->activity('always_fail', []));
            });
            self::fail('Expected DurableWorkflowAlgorithmFailureException');
        } catch (DurableWorkflowAlgorithmFailureException $e) {
            self::assertInstanceOf(DurableActivityFailedException::class, $e->getPrevious());
            self::assertStringContainsString('Always fails', $e->getPrevious()->getMessage());
        }
    }
}
