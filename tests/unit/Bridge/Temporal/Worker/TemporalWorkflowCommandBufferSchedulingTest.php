<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Journal\JournalExecutionIdResolver;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\WorkflowIdReusePolicy;
use Gplanchat\Durable\WorkflowTimeouts;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\ParentClosePolicy as TemporalParentClosePolicy;
use Temporal\Api\Enums\V1\WorkflowIdReusePolicy as TemporalIdReusePolicy;

/**
 * A StartTimer without `start_to_fire_timeout` is rejected by the server, and a
 * StartChildWorkflowExecution without a ParentClosePolicy falls back on the server
 * default: in both cases the option chosen by the caller was silently lost.
 */
final class TemporalWorkflowCommandBufferSchedulingTest extends TestCase
{
    private function buffer(): TemporalWorkflowCommandBuffer
    {
        return new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), ExecutionId::fromString('exec-1'));
    }

    public function testStartTimerCarriesTheDelayItWasGiven(): void
    {
        // The port passes a delay, no longer a deadline: no clock subtraction here, and therefore
        // no more drift due to poll latency.
        $buffer = $this->buffer();
        $buffer->startTimer('timer-1', Duration::seconds(42), '');

        $attrs = $buffer->peek()[0]->getStartTimerCommandAttributes();
        self::assertNotNull($attrs);
        self::assertSame('timer-1', $attrs->getTimerId());
        $timeout = $attrs->getStartToFireTimeout();
        self::assertNotNull($timeout);
        self::assertSame(42, $timeout->getSeconds());
        self::assertSame(0, $timeout->getNanos());
    }

    public function testSubSecondDelaysSurviveTheConversion(): void
    {
        $buffer = $this->buffer();
        $buffer->startTimer('timer-ms', Duration::milliseconds(250), '');

        $timeout = $buffer->peek()[0]->getStartTimerCommandAttributes()?->getStartToFireTimeout();
        self::assertNotNull($timeout);
        self::assertSame(0, $timeout->getSeconds());
        self::assertSame(250_000_000, $timeout->getNanos());
    }

    public function testChildWorkflowCarriesParentCloseAndIdReusePolicies(): void
    {
        $options = new ChildWorkflowOptions(
            parentClosePolicy: ParentClosePolicy::Abandon,
            taskQueue: TaskQueue::named('dedicated-queue'),
            timeouts: WorkflowTimeouts::run(Duration::seconds(120.0)),
            workflowIdReusePolicy: WorkflowIdReusePolicy::RejectDuplicate,
        );

        $buffer = $this->buffer();
        $buffer->scheduleChildWorkflow(ExecutionId::fromString('child-1'), 'ChildType', ['a' => 1], $options);

        $attrs = $buffer->peek()[0]->getStartChildWorkflowExecutionCommandAttributes();
        self::assertNotNull($attrs);
        self::assertSame(TemporalParentClosePolicy::PARENT_CLOSE_POLICY_ABANDON, $attrs->getParentClosePolicy());
        self::assertSame(TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_REJECT_DUPLICATE, $attrs->getWorkflowIdReusePolicy());
        self::assertSame('dedicated-queue', $attrs->getTaskQueue()?->getName());
        self::assertSame(120, $attrs->getWorkflowRunTimeout()?->getSeconds());
    }

    public function testChildWorkflowDefaultsToTerminateOnTheConnectionQueue(): void
    {
        $buffer = $this->buffer();
        $buffer->scheduleChildWorkflow(ExecutionId::fromString('child-2'), 'ChildType', [], ChildWorkflowOptions::defaults());

        $attrs = $buffer->peek()[0]->getStartChildWorkflowExecutionCommandAttributes();
        self::assertNotNull($attrs);
        self::assertSame(TemporalParentClosePolicy::PARENT_CLOSE_POLICY_TERMINATE, $attrs->getParentClosePolicy());
        self::assertSame(TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_ALLOW_DUPLICATE_FAILED_ONLY, $attrs->getWorkflowIdReusePolicy());
        self::assertNull($attrs->getMemo());
        self::assertNull($buffer->peek()[0]->getUserMetadata());
    }

    /**
     * #804: the journal backends record the memo, the summary and the details; the start command
     * dropped all three, so the Temporal UI showed a child without them.
     */
    public function testChildWorkflowCarriesItsMemoSummaryAndDetails(): void
    {
        $options = new ChildWorkflowOptions(
            memo: ['order' => 42, 'channel' => 'web'],
            staticSummary: 'Ship order 42',
            staticDetails: 'Two parcels, carrier chosen at runtime',
        );

        $buffer = $this->buffer();
        $buffer->scheduleChildWorkflow(ExecutionId::fromString('child-3'), 'ChildType', [], $options);

        $command = $buffer->peek()[0];
        $fields = $command->getStartChildWorkflowExecutionCommandAttributes()?->getMemo()?->getFields();
        self::assertNotNull($fields);
        self::assertSame(42, JsonPlainPayload::decode($fields['order']));
        self::assertSame('web', JsonPlainPayload::decode($fields['channel']));

        $metadata = $command->getUserMetadata();
        self::assertNotNull($metadata);
        self::assertNotNull($metadata->getSummary());
        self::assertNotNull($metadata->getDetails());
        self::assertSame('Ship order 42', JsonPlainPayload::decode($metadata->getSummary()));
        self::assertSame('Two parcels, carrier chosen at runtime', JsonPlainPayload::decode($metadata->getDetails()));
    }

    /**
     * The child's journal reads its execution id from this memo key, and the worker overwrites the
     * wait key at each suspension: a user value under either would be misread or lost. Since #896
     * the ChildWorkflowOptions constructor throws on these keys (#898), so options carrying one
     * never reach the buffer.
     */
    public function testChildWorkflowMemoRefusesTheKeysDurableWrites(): void
    {
        foreach ([JournalExecutionIdResolver::MEMO_KEY_DURABLE_EXECUTION_ID, JournalExecutionIdResolver::MEMO_KEY_DURABLE_WAITING_ON] as $key) {
            try {
                new ChildWorkflowOptions(memo: [$key => 'x']);
                self::fail(\sprintf('the memo key "%s" must be refused', $key));
            } catch (UnsupportedByBackendException $refusal) {
                $thrower = $refusal->getTrace()[0];
                self::assertSame(ChildWorkflowOptions::class, $thrower['class'] ?? null);
                self::assertSame('__construct', $thrower['function']);
                self::assertStringContainsString($key, $refusal->getMessage());
                self::assertStringContainsString('ChildWorkflowOptions::$memo', $refusal->getMessage());
            }
        }
    }

    public function testAnEmptySummaryAndDetailsCountAsNone(): void
    {
        $buffer = $this->buffer();
        $buffer->scheduleChildWorkflow(ExecutionId::fromString('child-5'), 'ChildType', [], new ChildWorkflowOptions(staticSummary: '', staticDetails: ''));

        self::assertNull($buffer->peek()[0]->getUserMetadata());
    }
}
