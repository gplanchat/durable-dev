<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * A workflow calling a Nexus operation under the in-memory harness must **fail fast**.
 *
 * The buffer test (§3.4) proves the command is refused. It does not prove what matters to whoever
 * writes a workflow: that the refusal **travels through** the engine instead of being swallowed
 * somewhere and leaving the execution suspended. That is the difference between a developer who
 * reads an error in three seconds and a developer watching a test that never finishes.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §5.1 §5.2
 */
final class NexusHarnessFailsFastTest extends TestCase
{
    public function testCallingNexusUnderTheInMemoryHarnessFailsInsteadOfHanging(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);

        $this->expectException(NexusUnsupportedByBackendException::class);

        $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await(
            $wf->nexusOperation('billing-endpoint', 'billing', 'charge', ['amount' => 10]),
        ));
    }

    public function testTheFailureNamesTheBackendAndTheWayOut(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);

        try {
            $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await(
                $wf->nexusOperation('billing-endpoint', 'billing', 'charge'),
            ));
            self::fail('The in-memory harness accepted a Nexus operation.');
        } catch (NexusUnsupportedByBackendException $e) {
            self::assertStringContainsString('journal', $e->getMessage());
            self::assertStringContainsString('Temporal', $e->getMessage());
        }
    }

    public function testTheWorkflowFailsBeforeAnythingIsAwaited(): void
    {
        // The refusal falls at scheduling, not at the wait: an `await()` never reached is what
        // tells "fail fast" apart from "fail after a delay".
        $env = WorkflowTestEnvironment::inMemory([]);
        $reached = false;

        try {
            $env->run(static function (WorkflowEnvironment $wf) use (&$reached): mixed {
                $awaitable = $wf->nexusOperation('billing-endpoint', 'billing', 'charge');
                $reached = true;

                return $wf->await($awaitable);
            });
        } catch (NexusUnsupportedByBackendException) {
            // expected
        }

        self::assertFalse($reached, 'The call returned an awaitable instead of refusing at once.');
    }
}
