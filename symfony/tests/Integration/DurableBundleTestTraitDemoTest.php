<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Samples\Workflow\ActivityRetry\ActivityRetryGreetingWorkflow;
use App\Samples\Workflow\Child\SamplesParentCallsEchoChildWorkflow;
use App\Samples\Workflow\Exception\ExceptionHandledWorkflow;
use App\Samples\Workflow\SimpleActivity\SimpleActivityGreetingWorkflow;
use Gplanchat\Durable\Bundle\Testing\DurableBundleTestTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Demonstrates how consumers of gplanchat/durable-bundle can write integration tests
 * using {@see DurableBundleTestTrait} against real workflow classes, with the Symfony
 * Kernel loaded in test mode (Messenger in-memory transports).
 *
 * Intended as a living example and regression guard for the testing toolkit itself.
 *
 * @internal
 */
#[Group('integration')]
#[Group('bundle-testing-trait')]
final class DurableBundleTestTraitDemoTest extends KernelTestCase
{
    use DurableBundleTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /**
     * Happy path: SimpleActivityGreetingWorkflow dispatched via the trait,
     * Messenger drained in-process, result asserted.
     */
    public function testSimpleActivityWorkflowCompletesViaTestTrait(): void
    {
        $executionId = $this->dispatchWorkflow(SimpleActivityGreetingWorkflow::class, ['name' => 'World']);

        $this->drainMessengerUntilSettled($executionId);

        $this->assertWorkflowResultEquals($executionId, 'Hello, World!');
    }

    /**
     * Verifies that getEventStoreService() returns an event store populated with the
     * execution's events after the workflow settles.
     */
    public function testEventStoreIsAccessibleAfterWorkflowSettles(): void
    {
        $executionId = $this->dispatchWorkflow(SimpleActivityGreetingWorkflow::class, ['name' => 'Durable']);

        $this->drainMessengerUntilSettled($executionId);

        $events = iterator_to_array($this->getEventStoreService()->readStream($executionId));
        self::assertNotEmpty($events, 'The event store should contain events for the settled execution.');
    }

    /**
     * A workflow that retries an activity should still settle successfully.
     */
    public function testActivityRetryWorkflowSettles(): void
    {
        $executionId = $this->dispatchWorkflow(ActivityRetryGreetingWorkflow::class, ['name' => 'World']);

        $this->drainMessengerUntilSettled($executionId);

        $this->assertWorkflowResultEquals($executionId, 'Hello, World!');
    }

    /**
     * A workflow that handles an activity exception internally should still complete,
     * returning the caught exception message.
     */
    public function testExceptionHandledWorkflowCompletes(): void
    {
        $executionId = $this->dispatchWorkflow(ExceptionHandledWorkflow::class, ['shouldFail' => true]);

        $this->drainMessengerUntilSettled($executionId);

        // ExceptionHandledWorkflow catches DurableActivityFailedException and returns 'Caught: …'
        $result = \Gplanchat\Durable\Query\WorkflowQueryEvaluator::lastExecutionResult(
            $this->getEventStoreService(),
            $executionId,
        );
        self::assertIsString($result);
        self::assertStringStartsWith('Caught: ', $result);
    }

    /**
     * Explicit execution ID: caller controls the ID, trait respects it.
     */
    public function testExplicitExecutionIdIsPreserved(): void
    {
        $executionId = 'test-explicit-exec-' . bin2hex(random_bytes(4));

        $returned = $this->dispatchWorkflow(SimpleActivityGreetingWorkflow::class, ['name' => 'Test'], $executionId);

        self::assertSame($executionId, $returned, 'dispatchWorkflow must return the execution ID passed as third argument.');

        $this->drainMessengerUntilSettled($executionId);
        $this->assertWorkflowResultEquals($executionId, 'Hello, Test!');
    }

    /**
     * Child workflow scenario: parent dispatches a child via ChildWorkflowRunner.
     */
    public function testParentChildWorkflowSettles(): void
    {
        $executionId = $this->dispatchWorkflow(SamplesParentCallsEchoChildWorkflow::class);

        $this->drainMessengerUntilSettled($executionId);

        $this->assertWorkflowResultEquals($executionId, 'CHILD');
    }
}
