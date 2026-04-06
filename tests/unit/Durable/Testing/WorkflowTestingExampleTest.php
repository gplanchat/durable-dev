<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Testing;

use Gplanchat\Durable\Testing\ActivitySpy;
use Gplanchat\Durable\Testing\DurableTestCase;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Exemples d'utilisation de l'infrastructure de test in-memory.
 *
 * Ce fichier sert à la fois de validation fonctionnelle et de documentation
 * par l'exemple pour les utilisateurs du composant.
 */
final class WorkflowTestingExampleTest extends DurableTestCase
{
    // -------------------------------------------------------------------------
    // 1. Test unitaire pur : DurableTestCase + ActivitySpy
    // -------------------------------------------------------------------------

    #[Test]
    public function workflowWithSingleActivityCompletes(): void
    {
        $spy = ActivitySpy::returns('Hello, World!');

        $env = $this->createWorkflowTestEnvironment(['greet' => $spy]);

        $result = $env->run(
            static function (WorkflowEnvironment $wf): string {
                return (string) $wf->await($wf->activity('greet', ['name' => 'World']));
            },
            $executionId = 'exec-example-001',
        );

        self::assertSame('Hello, World!', $result);

        $spy->assertCalledTimes(1);
        $spy->assertCalledWith(['name' => 'World']);

        $this->assertWorkflowCompleted($executionId, 'Hello, World!');
        $this->assertActivityExecuted($executionId, 'greet');
        $this->assertEventStoreContains($executionId, \Gplanchat\Durable\Event\ExecutionCompleted::class);
    }

    #[Test]
    public function workflowWithParallelActivitiesCompletes(): void
    {
        $doublespy = ActivitySpy::returns(6);
        $squareSpy = ActivitySpy::returns(16);

        $env = $this->createWorkflowTestEnvironment([
            'double' => $doublespy,
            'square' => $squareSpy,
        ]);

        $result = $env->run(
            static function (WorkflowEnvironment $wf): array {
                return $wf->all(
                    $wf->activity('double', ['value' => 3]),
                    $wf->activity('square', ['value' => 4]),
                );
            },
            $executionId = 'exec-parallel-001',
        );

        self::assertIsArray($result);
        self::assertSame(6, $result[0]);
        self::assertSame(16, $result[1]);

        $doublespy->assertCalledTimes(1);
        $squareSpy->assertCalledTimes(1);
        $this->assertWorkflowCompleted($executionId, [6, 16]);
    }

    #[Test]
    public function workflowWithRetryHandlesActivityFailure(): void
    {
        // Les Throwable dans la séquence sont levés lors de l'appel correspondant
        $spy = ActivitySpy::returnsSequence(
            new \RuntimeException('Temporary failure'),
            new \RuntimeException('Still failing'),
            'Success after retries',
        );

        $env = WorkflowTestEnvironment::inMemory(['flaky' => $spy], maxActivityRetries: 2);

        $result = $env->run(
            static function (WorkflowEnvironment $wf): string {
                try {
                    return (string) $wf->await($wf->activity('flaky', []));
                } catch (\Throwable $e) {
                    return 'caught: '.$e->getMessage();
                }
            },
            $executionId = 'exec-retry-001',
        );

        self::assertStringContainsString('Success after retries', (string) $result);
        $spy->assertCalledTimes(3);
    }

    #[Test]
    public function workflowFailsWhenActivityThrowsUnhandled(): void
    {
        $spy = ActivitySpy::throws(new \DomainException('Business rule violated', 42));

        $env = $this->createWorkflowTestEnvironment(['validate' => $spy]);

        $executionId = 'exec-fail-001';

        $this->expectException(\Throwable::class);

        $env->run(
            static function (WorkflowEnvironment $wf): void {
                $wf->await($wf->activity('validate', ['data' => 'invalid']));
            },
            $executionId,
        );
    }

    #[Test]
    public function workflowFailureIsRecordedInEventStore(): void
    {
        $spy = ActivitySpy::throws(new \RuntimeException('Activity bombed'));

        $env = $this->createWorkflowTestEnvironment(['explode' => $spy]);

        $executionId = 'exec-fail-002';

        try {
            $env->run(
                static function (WorkflowEnvironment $wf): void {
                    $wf->await($wf->activity('explode', []));
                },
                $executionId,
            );
        } catch (\Throwable) {
            // L'exception remonte ; on vérifie que l'event store contient WorkflowExecutionFailed
        }

        $this->assertWorkflowFailed($executionId);
    }

    // -------------------------------------------------------------------------
    // 2. ActivitySpy : séquence de retours
    // -------------------------------------------------------------------------

    #[Test]
    public function spyReturnsSequentialValues(): void
    {
        $spy = ActivitySpy::returnsSequence('first', 'second', 'third');

        $env = WorkflowTestEnvironment::inMemory(['step' => $spy]);

        $result = $env->run(
            static function (WorkflowEnvironment $wf): array {
                return [
                    $wf->await($wf->activity('step', [])),
                    $wf->await($wf->activity('step', [])),
                    $wf->await($wf->activity('step', [])),
                ];
            },
        );

        self::assertSame(['first', 'second', 'third'], $result);
        $spy->assertCalledTimes(3);
    }

    #[Test]
    public function spyLastValueIsRepeatedWhenSequenceExhausted(): void
    {
        $spy = ActivitySpy::returnsSequence('value-1', 'value-2');

        $env = WorkflowTestEnvironment::inMemory(['step' => $spy]);

        $result = $env->run(
            static function (WorkflowEnvironment $wf): array {
                return [
                    $wf->await($wf->activity('step', [])),
                    $wf->await($wf->activity('step', [])),
                    $wf->await($wf->activity('step', [])), // séquence épuisée : répète la dernière
                ];
            },
        );

        self::assertSame(['value-1', 'value-2', 'value-2'], $result);
    }

    // -------------------------------------------------------------------------
    // 3. WorkflowTestEnvironment standalone (sans DurableTestCase)
    // -------------------------------------------------------------------------

    #[Test]
    public function standaloneEnvironmentCanBeUsedWithoutTestCase(): void
    {
        $env = WorkflowTestEnvironment::inMemory([
            'compute' => static fn (array $p): int => ($p['a'] ?? 0) + ($p['b'] ?? 0),
        ]);

        $executionId = 'exec-standalone-001';

        $result = $env->run(
            static function (WorkflowEnvironment $wf): int {
                return (int) $wf->await($wf->activity('compute', ['a' => 3, 'b' => 4]));
            },
            $executionId,
        );

        self::assertSame(7, $result);

        // Inspection directe du journal
        $hasCompleted = false;
        foreach ($env->getEventStore()->readStream($executionId) as $event) {
            if ($event instanceof \Gplanchat\Durable\Event\ExecutionCompleted) {
                $hasCompleted = true;
                break;
            }
        }
        self::assertTrue($hasCompleted, 'Le journal doit contenir ExecutionCompleted');
    }

    // -------------------------------------------------------------------------
    // 4. countActivityExecutions helper
    // -------------------------------------------------------------------------

    #[Test]
    public function countActivityExecutionsCountsCorrectly(): void
    {
        $env = $this->createWorkflowTestEnvironment([
            'add' => static fn (array $p): int => ($p['a'] ?? 0) + ($p['b'] ?? 0),
        ]);

        $env->run(
            static function (WorkflowEnvironment $wf): array {
                return $wf->parallel(
                    $wf->activity('add', ['a' => 1, 'b' => 2]),
                    $wf->activity('add', ['a' => 3, 'b' => 4]),
                    $wf->activity('add', ['a' => 5, 'b' => 6]),
                );
            },
            $executionId = 'exec-count-001',
        );

        self::assertSame(3, $this->countActivityExecutions($executionId, 'add'));
    }
}
