<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Exception\ResumeArrivedBeforeItsOutcome;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CoreResumeWithoutASymfonyBusTest.php';

/**
 * DUR050: the activity worker sends the resume before it appends the outcome, so a resume may
 * arrive first. It then concludes nothing: it throws, and the transport's retry is the wait.
 */
final class AnEarlyResumeWaitsForItsOutcomeTest extends TestCase
{
    public function testAResumeBeforeItsOutcomeThrowsAndRunsNothing(): void
    {
        [$store, $metadata, $handler] = $this->handler();

        try {
            $handler(new ResumeWorkflowMessage('exec-1', [], AwaitedFact::activity('act-1')));
            self::fail('an early resume must not conclude');
        } catch (ResumeArrivedBeforeItsOutcome $e) {
            self::assertSame('exec-1', $e->executionId);
            self::assertEquals(AwaitedFact::activity('act-1'), $e->awaited);
        }

        self::assertSame([], iterator_to_array($store->readStream(ExecutionId::fromString('exec-1')), false), 'the workflow did not run');
        self::assertFalse($metadata->get(ExecutionId::fromString('exec-1'))['completed'] ?? false);
    }

    public function testAResumeThatFindsItsOutcomeProceeds(): void
    {
        [$store, $metadata, $handler] = $this->handler();
        $store->append(new ActivityCompleted(ExecutionId::fromString('exec-1'), 'act-1', 'ch_1'));

        $handler(new ResumeWorkflowMessage('exec-1', [], AwaitedFact::activity('act-1')));

        self::assertTrue($metadata->get(ExecutionId::fromString('exec-1'))['completed'] ?? false);
    }

    /**
     * A message serialized before the upgrade has no awaited activity: it reads back as a resume
     * that announces nothing in particular, instead of failing on an uninitialized property.
     */
    public function testAMessageSerializedBeforeTheUpgradeStillReads(): void
    {
        $old = 'O:49:"Gplanchat\Durable\Transport\ResumeWorkflowMessage":2:{s:11:"executionId";s:6:"exec-1";s:14:"pendingUpdates";a:0:{}}';

        $message = unserialize($old);

        self::assertInstanceOf(ResumeWorkflowMessage::class, $message);
        self::assertSame('exec-1', $message->executionId);
        self::assertNull($message->awaited);
    }

    /**
     * Between DUR050 and DUR052 the message carried the activity id as `awaitedActivityId`; such a
     * message reads back as an activity fact.
     */
    public function testAMessageSerializedWithAnAwaitedActivityIdReadsAsAnActivityFact(): void
    {
        $old = 'O:49:"Gplanchat\Durable\Transport\ResumeWorkflowMessage":3:{s:11:"executionId";s:6:"exec-1";s:14:"pendingUpdates";a:0:{}s:17:"awaitedActivityId";s:5:"act-1";}';

        $message = unserialize($old);

        self::assertInstanceOf(ResumeWorkflowMessage::class, $message);
        self::assertEquals(AwaitedFact::activity('act-1'), $message->awaited);
    }

    /**
     * @return array{InMemoryEventStore, InMemoryWorkflowMetadataStore, ResumeWorkflowHandler}
     */
    private function handler(): array
    {
        $store = new InMemoryEventStore();
        $metadata = new InMemoryWorkflowMetadataStore();
        $registry = new WorkflowRegistry();
        $registry->registerClass(ImmediateWorkflow::class);
        $metadata->save(ExecutionId::fromString('exec-1'), ImmediateWorkflow::class, ['name' => 'Ada']);
        $engine = new ExecutionEngine($store, new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true));

        return [$store, $metadata, new ResumeWorkflowHandler(
            $engine,
            $registry,
            $metadata,
            new NullWorkflowResumeDispatcher(),
            $store,
            new InMemoryChildWorkflowParentLinkStore(),
            new RecordingTimerDispatcher(),
            new WorkflowDefinitionLoader(),
        )];
    }
}
