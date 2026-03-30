<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Handler;

use Gplanchat\Durable\Bundle\Support\AsyncChildWorkflowFailureProjector;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\WorkflowRunMessage;
use Gplanchat\Durable\WorkflowRegistry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class WorkflowRunHandler
{
    public function __construct(
        private readonly ExecutionEngine $engine,
        private readonly WorkflowRegistry $workflowRegistry,
        private readonly WorkflowMetadataStore $metadataStore,
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
        private readonly EventStoreInterface $eventStore,
        private readonly ChildWorkflowParentLinkStoreInterface $childWorkflowParentLinkStore,
    ) {
    }

    public function __invoke(WorkflowRunMessage $message): void
    {
        $executionId = $message->executionId;

        if ($message->isResume()) {
            $metadata = $this->metadataStore->get($executionId);
            if (null === $metadata) {
                return;
            }
            $workflowType = $metadata['workflowType'];
            $payload = $metadata['payload'];
        } else {
            $workflowType = $message->workflowType;
            $payload = $message->payload;
            $this->metadataStore->save($executionId, $workflowType, $payload);
        }

        $handler = $this->workflowRegistry->getHandler($workflowType, $payload);

        $run = $message->isResume()
            ? fn () => $this->engine->resume($executionId, $handler, $workflowType)
            : fn () => $this->engine->start($executionId, $handler, $workflowType);

        try {
            $result = $run();
        } catch (WorkflowSuspendedException $e) {
            if ($e->shouldDispatchResume()) {
                $this->resumeDispatcher->dispatchResume($executionId);
            }

            return;
        } catch (ContinueAsNewRequested $e) {
            $this->metadataStore->delete($executionId);
            $newExecutionId = (string) Uuid::v7();
            $this->metadataStore->save($newExecutionId, $e->workflowType, $e->payload);
            $this->resumeDispatcher->dispatchNewWorkflowRun($newExecutionId, $e->workflowType, $e->payload);

            return;
        } catch (\Throwable $e) {
            $this->finalizeAsyncChildOnParentIfLinked($executionId, null, $e);
            $this->metadataStore->delete($executionId);

            throw $e;
        }

        $this->finalizeAsyncChildOnParentIfLinked($executionId, $result, null);
        $this->metadataStore->delete($executionId);
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
