<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Support\Constraint;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Util\Exporter;

/**
 * Contrainte PHPUnit : le flux d'événements d'un InMemoryEventStore est sémantiquement
 * équivalent au journal attendu (runtime distribué). Les UUID d'activité ne sont pas comparés.
 */
final class DistributedWorkflowJournalEquivalentConstraint extends Constraint
{
    private ?string $failureDetail = null;

    public function __construct(
        private readonly InMemoryEventStore $expectedJournal,
        private readonly ExecutionId $executionId,
    ) {
    }

    public function toString(): string
    {
        return \sprintf(
            'journal d\'événements équivalent au scénario attendu pour l\'exécution %s',
            $this->executionId->toString(),
        );
    }

    protected function matches(mixed $other): bool
    {
        $this->failureDetail = null;

        if (!$other instanceof InMemoryEventStore) {
            $this->failureDetail = 'la valeur réelle doit être une instance de '.InMemoryEventStore::class;

            return false;
        }

        $detail = self::compareJournals($other, $this->expectedJournal, $this->executionId);
        if (null !== $detail) {
            $this->failureDetail = $detail;

            return false;
        }

        return true;
    }

    protected function failureDescription(mixed $other): string
    {
        if (null !== $this->failureDetail) {
            return $this->failureDetail;
        }

        return Exporter::export($other).' '.$this->toString();
    }

    /**
     * @return non-empty-string|null null si équivalent, sinon message d'échec
     */
    public static function compareJournals(
        InMemoryEventStore $actual,
        InMemoryEventStore $expected,
        ExecutionId $executionId,
    ): ?string {
        $executionKey = $executionId->toString();
        $a = iterator_to_array($actual->readStream($executionKey));
        $e = iterator_to_array($expected->readStream($executionKey));

        if (\count($a) !== \count($e)) {
            return \sprintf(
                'nombre d\'événements : attendu %d, obtenu %d',
                \count($e),
                \count($a),
            );
        }

        foreach ($e as $i => $expectedEvent) {
            $msg = self::compareEventPair($expectedEvent, $a[$i], $i);
            if (null !== $msg) {
                return $msg;
            }
        }

        return null;
    }

    /**
     * @return non-empty-string|null
     */
    private static function compareEventPair(Event $expected, Event $actual, int $index): ?string
    {
        if ($expected::class !== $actual::class) {
            return \sprintf(
                'événement #%d : type attendu %s, obtenu %s',
                $index,
                $expected::class,
                $actual::class,
            );
        }

        if ($expected instanceof ExecutionStarted && $actual instanceof ExecutionStarted) {
            if ($expected->executionId() !== $actual->executionId()) {
                return \sprintf('événement #%d : ExecutionStarted.executionId incohérent', $index);
            }

            return null;
        }

        if ($expected instanceof ActivityScheduled && $actual instanceof ActivityScheduled) {
            if ($expected->activityName() !== $actual->activityName()) {
                return \sprintf('événement #%d : ActivityScheduled.activityName', $index);
            }
            if ($expected->payload()['payload'] !== $actual->payload()['payload']) {
                return \sprintf('événement #%d : ActivityScheduled payload métier', $index);
            }

            return null;
        }

        if ($expected instanceof ActivityCompleted && $actual instanceof ActivityCompleted) {
            if ($expected->result() !== $actual->result()) {
                return \sprintf('événement #%d : ActivityCompleted.result', $index);
            }

            return null;
        }

        if ($expected instanceof ActivityCancelled && $actual instanceof ActivityCancelled) {
            if ($expected->reason() !== $actual->reason()) {
                return \sprintf('événement #%d : ActivityCancelled.reason', $index);
            }

            return null;
        }

        if ($expected instanceof ExecutionCompleted && $actual instanceof ExecutionCompleted) {
            if ($expected->result() !== $actual->result()) {
                return \sprintf('événement #%d : ExecutionCompleted.result', $index);
            }

            return null;
        }

        if ($expected instanceof ChildWorkflowScheduled && $actual instanceof ChildWorkflowScheduled) {
            if ($expected->childExecutionId() !== $actual->childExecutionId()) {
                return \sprintf('événement #%d : ChildWorkflowScheduled.childExecutionId', $index);
            }
            if ($expected->childWorkflowType() !== $actual->childWorkflowType()) {
                return \sprintf('événement #%d : ChildWorkflowScheduled.childWorkflowType', $index);
            }
            if ($expected->input() !== $actual->input()) {
                return \sprintf('événement #%d : ChildWorkflowScheduled.input', $index);
            }
            if ($expected->parentClosePolicy() !== $actual->parentClosePolicy()) {
                return \sprintf('événement #%d : ChildWorkflowScheduled.parentClosePolicy', $index);
            }
            if ($expected->requestedWorkflowId() !== $actual->requestedWorkflowId()) {
                return \sprintf('événement #%d : ChildWorkflowScheduled.requestedWorkflowId', $index);
            }
            if ($expected->schedulingMetadata() !== $actual->schedulingMetadata()) {
                return \sprintf('événement #%d : ChildWorkflowScheduled.schedulingMetadata', $index);
            }

            return null;
        }

        if ($expected instanceof ChildWorkflowCompleted && $actual instanceof ChildWorkflowCompleted) {
            if ($expected->result() !== $actual->result()) {
                return \sprintf('événement #%d : ChildWorkflowCompleted.result', $index);
            }

            return null;
        }

        if ($expected instanceof ChildWorkflowFailed && $actual instanceof ChildWorkflowFailed) {
            if ($expected->failureMessage() !== $actual->failureMessage()) {
                return \sprintf('événement #%d : ChildWorkflowFailed.failureMessage', $index);
            }
            if ($expected->failureCode() !== $actual->failureCode()) {
                return \sprintf('événement #%d : ChildWorkflowFailed.failureCode', $index);
            }
            if ($expected->workflowFailureKind() !== $actual->workflowFailureKind()) {
                return \sprintf('événement #%d : ChildWorkflowFailed.workflowFailureKind', $index);
            }
            if ($expected->workflowFailureClass() !== $actual->workflowFailureClass()) {
                return \sprintf('événement #%d : ChildWorkflowFailed.workflowFailureClass', $index);
            }
            if ($expected->workflowFailureContext() !== $actual->workflowFailureContext()) {
                return \sprintf('événement #%d : ChildWorkflowFailed.workflowFailureContext', $index);
            }

            return null;
        }

        if ($expected instanceof WorkflowSignalReceived && $actual instanceof WorkflowSignalReceived) {
            if ($expected->signalName() !== $actual->signalName()) {
                return \sprintf('événement #%d : WorkflowSignalReceived.signalName', $index);
            }
            if ($expected->signalPayload() !== $actual->signalPayload()) {
                return \sprintf('événement #%d : WorkflowSignalReceived.signalPayload', $index);
            }

            return null;
        }

        if ($expected instanceof WorkflowUpdateHandled && $actual instanceof WorkflowUpdateHandled) {
            if ($expected->updateName() !== $actual->updateName()) {
                return \sprintf('événement #%d : WorkflowUpdateHandled.updateName', $index);
            }
            if ($expected->arguments() !== $actual->arguments()) {
                return \sprintf('événement #%d : WorkflowUpdateHandled.arguments', $index);
            }
            if ($expected->result() !== $actual->result()) {
                return \sprintf('événement #%d : WorkflowUpdateHandled.result', $index);
            }

            return null;
        }

        if ($expected instanceof WorkflowContinuedAsNew && $actual instanceof WorkflowContinuedAsNew) {
            if ($expected->nextWorkflowType() !== $actual->nextWorkflowType()) {
                return \sprintf('événement #%d : WorkflowContinuedAsNew.nextWorkflowType', $index);
            }
            if ($expected->nextPayload() !== $actual->nextPayload()) {
                return \sprintf('événement #%d : WorkflowContinuedAsNew.nextPayload', $index);
            }
            if ($expected->continuationMetadata() !== $actual->continuationMetadata()) {
                return \sprintf('événement #%d : WorkflowContinuedAsNew.continuationMetadata', $index);
            }

            return null;
        }

        if ($expected instanceof WorkflowCancellationRequested && $actual instanceof WorkflowCancellationRequested) {
            if ($expected->reason() !== $actual->reason()) {
                return \sprintf('événement #%d : WorkflowCancellationRequested.reason', $index);
            }
            if ($expected->sourceParentExecutionId() !== $actual->sourceParentExecutionId()) {
                return \sprintf('événement #%d : WorkflowCancellationRequested.sourceParentExecutionId', $index);
            }

            return null;
        }

        return \sprintf('événement #%d : type %s non géré pour la comparaison', $index, $expected::class);
    }
}
