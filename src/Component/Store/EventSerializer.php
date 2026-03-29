<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\ParentClosePolicy;

final class EventSerializer
{
    /** @var array<string, callable(string, array): Event> */
    private static array $hydrators = [
        ExecutionStarted::class => [self::class, 'hydrateExecutionStarted'],
        ExecutionCompleted::class => [self::class, 'hydrateExecutionCompleted'],
        ActivityScheduled::class => [self::class, 'hydrateActivityScheduled'],
        ActivityCancelled::class => [self::class, 'hydrateActivityCancelled'],
        ActivityCompleted::class => [self::class, 'hydrateActivityCompleted'],
        ActivityFailed::class => [self::class, 'hydrateActivityFailed'],
        ActivityCatastrophicFailure::class => [self::class, 'hydrateActivityCatastrophicFailure'],
        WorkflowExecutionFailed::class => [self::class, 'hydrateWorkflowExecutionFailed'],
        TimerScheduled::class => [self::class, 'hydrateTimerScheduled'],
        TimerCompleted::class => [self::class, 'hydrateTimerCompleted'],
        SideEffectRecorded::class => [self::class, 'hydrateSideEffectRecorded'],
        ChildWorkflowScheduled::class => [self::class, 'hydrateChildWorkflowScheduled'],
        ChildWorkflowCompleted::class => [self::class, 'hydrateChildWorkflowCompleted'],
        ChildWorkflowFailed::class => [self::class, 'hydrateChildWorkflowFailed'],
        WorkflowContinuedAsNew::class => [self::class, 'hydrateWorkflowContinuedAsNew'],
        WorkflowSignalReceived::class => [self::class, 'hydrateWorkflowSignalReceived'],
        WorkflowUpdateHandled::class => [self::class, 'hydrateWorkflowUpdateHandled'],
        WorkflowCancellationRequested::class => [self::class, 'hydrateWorkflowCancellationRequested'],
    ];

    public static function serialize(Event $event): array
    {
        return [
            'execution_id' => $event->executionId(),
            'event_type' => $event::class,
            'payload' => $event->payload(),
        ];
    }

    public static function deserialize(array $row): Event
    {
        $eventType = $row['event_type'];
        $executionId = $row['execution_id'];
        $payload = \is_string($row['payload']) ? json_decode($row['payload'], true, 512, \JSON_THROW_ON_ERROR) : $row['payload'];

        $hydrator = self::$hydrators[$eventType] ?? null;
        if (null === $hydrator) {
            throw new \InvalidArgumentException(\sprintf('Unknown event type: %s', $eventType));
        }

        return $hydrator($executionId, $payload);
    }

    private static function hydrateExecutionStarted(string $executionId, array $p): Event
    {
        return new ExecutionStarted($executionId, $p);
    }

    private static function hydrateExecutionCompleted(string $executionId, array $p): Event
    {
        return new ExecutionCompleted($executionId, $p['result'] ?? null);
    }

    private static function hydrateActivityScheduled(string $executionId, array $p): Event
    {
        return new ActivityScheduled(
            $executionId,
            $p['activityId'],
            $p['activityName'],
            $p['payload'] ?? [],
            $p['metadata'] ?? [],
        );
    }

    private static function hydrateActivityCancelled(string $executionId, array $p): Event
    {
        return new ActivityCancelled(
            $executionId,
            $p['activityId'],
            $p['reason'],
        );
    }

    private static function hydrateActivityCompleted(string $executionId, array $p): Event
    {
        return new ActivityCompleted($executionId, $p['activityId'], $p['result']);
    }

    private static function hydrateActivityFailed(string $executionId, array $p): Event
    {
        return new ActivityFailed(
            $executionId,
            $p['activityId'],
            $p['failureClass'],
            $p['failureMessage'],
            $p['failureCode'] ?? 0,
            $p['failureContext'] ?? [],
            $p['failureTrace'] ?? '',
            \is_array($p['failurePrevious'] ?? null) ? $p['failurePrevious'] : [],
            $p['activityName'] ?? '',
            (int) ($p['failureAttempt'] ?? 0),
        );
    }

    private static function hydrateActivityCatastrophicFailure(string $executionId, array $p): Event
    {
        return ActivityCatastrophicFailure::fromStoredPayload($executionId, $p);
    }

    private static function hydrateWorkflowExecutionFailed(string $executionId, array $p): Event
    {
        return WorkflowExecutionFailed::fromStoredPayload($executionId, $p);
    }

    private static function hydrateTimerScheduled(string $executionId, array $p): Event
    {
        return new TimerScheduled($executionId, $p['timerId'], (float) $p['scheduledAt']);
    }

    private static function hydrateTimerCompleted(string $executionId, array $p): Event
    {
        return new TimerCompleted($executionId, $p['timerId']);
    }

    private static function hydrateSideEffectRecorded(string $executionId, array $p): Event
    {
        return new SideEffectRecorded($executionId, $p['sideEffectId'], $p['result']);
    }

    private static function hydrateChildWorkflowScheduled(string $executionId, array $p): Event
    {
        $policyValue = $p['parentClosePolicy'] ?? ParentClosePolicy::Terminate->value;
        $policy = ParentClosePolicy::from(\is_string($policyValue) ? $policyValue : ParentClosePolicy::Terminate->value);

        $requestedWorkflowId = $p['requestedWorkflowId'] ?? null;
        if ('' === $requestedWorkflowId) {
            $requestedWorkflowId = null;
        }

        return new ChildWorkflowScheduled(
            $executionId,
            $p['childExecutionId'],
            $p['childWorkflowType'],
            $p['input'] ?? [],
            $policy,
            \is_string($requestedWorkflowId) ? $requestedWorkflowId : null,
        );
    }

    private static function hydrateChildWorkflowCompleted(string $executionId, array $p): Event
    {
        return new ChildWorkflowCompleted($executionId, $p['childExecutionId'], $p['result']);
    }

    private static function hydrateChildWorkflowFailed(string $executionId, array $p): Event
    {
        $ctx = $p['workflowFailureContext'] ?? [];
        if (!\is_array($ctx)) {
            $ctx = [];
        }

        return new ChildWorkflowFailed(
            $executionId,
            $p['childExecutionId'],
            $p['failureMessage'],
            (int) ($p['failureCode'] ?? 0),
            isset($p['workflowFailureKind']) ? (string) $p['workflowFailureKind'] : null,
            isset($p['workflowFailureClass']) ? (string) $p['workflowFailureClass'] : null,
            $ctx,
        );
    }

    private static function hydrateWorkflowContinuedAsNew(string $executionId, array $p): Event
    {
        return new WorkflowContinuedAsNew(
            $executionId,
            $p['nextWorkflowType'],
            $p['nextPayload'] ?? [],
        );
    }

    private static function hydrateWorkflowSignalReceived(string $executionId, array $p): Event
    {
        return new WorkflowSignalReceived(
            $executionId,
            $p['signalName'],
            \is_array($p['signalPayload'] ?? null) ? $p['signalPayload'] : [],
        );
    }

    private static function hydrateWorkflowUpdateHandled(string $executionId, array $p): Event
    {
        return new WorkflowUpdateHandled(
            $executionId,
            $p['updateName'],
            $p['arguments'] ?? [],
            $p['result'],
        );
    }

    private static function hydrateWorkflowCancellationRequested(string $executionId, array $p): Event
    {
        $source = $p['sourceParentExecutionId'] ?? null;
        if ('' === $source) {
            $source = null;
        }

        return new WorkflowCancellationRequested(
            $executionId,
            $p['reason'],
            \is_string($source) ? $source : null,
        );
    }
}
