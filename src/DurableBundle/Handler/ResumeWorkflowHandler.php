<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Handler;

use Gplanchat\Durable\Bundle\Messenger\TimerWakeDelayCalculator;
use Gplanchat\Durable\Bundle\Support\AsyncChildWorkflowFailureProjector;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class ResumeWorkflowHandler
{
    public function __construct(
        private readonly ExecutionEngine $engine,
        private readonly WorkflowRegistry $workflowRegistry,
        private readonly WorkflowMetadataStore $metadataStore,
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
        private readonly EventStoreInterface $eventStore,
        private readonly ChildWorkflowParentLinkStoreInterface $childWorkflowParentLinkStore,
        private readonly MessageBusInterface $messageBus,
        private readonly WorkflowDefinitionLoader $workflowDefinitionLoader,
    ) {
    }

    public function __invoke(ResumeWorkflowMessage $message): void
    {
        $executionId = $message->executionId;

        $metadata = $this->metadataStore->get($executionId);
        if (null === $metadata) {
            return;
        }
        if (($metadata['completed'] ?? false) === true) {
            return;
        }

        $lookupKey = $metadata['workflowType'];
        $payload = $metadata['payload'];

        $handler = $this->workflowRegistry->getHandler($lookupKey, $payload);
        $workflowTypeForJournal = $this->workflowDefinitionLoader->aliasForTemporalInterop($lookupKey);

        try {
            $result = $this->engine->resume($executionId, $handler, $workflowTypeForJournal);
        } catch (WorkflowSuspendedException $e) {
            if ($e->shouldDispatchResume()) {
                if (!$e->waitingOnTimer()) {
                    $this->resumeDispatcher->dispatchResume($executionId);
                } else {
                    $ms = TimerWakeDelayCalculator::millisecondsUntilNextTimerDue(
                        $this->eventStore,
                        $executionId,
                        $this->engine->getRuntime()->nowSeconds(),
                    );
                    if (null === $ms) {
                        $ms = 0;
                    }
                    $stamps = [new DispatchAfterCurrentBusStamp()];
                    if ($ms > 0) {
                        $stamps[] = new DelayStamp($ms);
                    }
                    $this->messageBus->dispatch(new Envelope(new FireWorkflowTimersMessage($executionId), $stamps));
                }
            }

            return;
        } catch (ContinueAsNewRequested $e) {
            $this->metadataStore->delete($executionId);
            $newExecutionId = (string) Uuid::v7();
            $nextAlias = $this->workflowDefinitionLoader->aliasForTemporalInterop($e->workflowType);
            $this->metadataStore->save($newExecutionId, $nextAlias, $e->payload);
            $this->resumeDispatcher->dispatchNewWorkflowRun($newExecutionId, $nextAlias, $e->payload);

            return;
        } catch (\Throwable $e) {
            $this->finalizeAsyncChildOnParentIfLinked($executionId, null, $e);
            $this->metadataStore->delete($executionId);

            throw $e;
        }

        $this->finalizeAsyncChildOnParentIfLinked($executionId, $result, null);
        $this->metadataStore->markCompleted($executionId);
    }

    private function finalizeAsyncChildOnParentIfLinked(string $childExecutionId, mixed $result, ?\Throwable $failure): void
    {
        $parentId = $this->childWorkflowParentLinkStore->getParentExecutionId($childExecutionId);
        if (null === $parentId) {
            return;
        }

        if (null !== $failure) {
            $this->eventStore->append(AsyncChildWorkflowFailureProjector::toParentJournalEvent(
                $this->eventStore,
                $parentId,
                $childExecutionId,
                $failure,
            ));
        } else {
            $this->eventStore->append(new ChildWorkflowCompleted($parentId, $childExecutionId, $result));
        }

        $this->childWorkflowParentLinkStore->unlink($childExecutionId);
        $this->resumeDispatcher->dispatchResume($parentId);
    }
}
