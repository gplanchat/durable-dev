<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Profiler;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;

/**
 * Reading labels for the web profiler (title + subtitle + display category).
 *
 * The PHP class names stay in the payload; these are the words a human reads above them.
 */
final class DurableProfilerEventPresentation
{
    /**
     * @param array<string, mixed> $entry one {@see DurableExecutionTrace} entry
     *
     * @return array{title: string, subtitle: string, category: string}
     */
    /**
     * The label for the "chronological order" table and for the dispatch details.
     *
     * A resume {@see \Gplanchat\Durable\Transport\WorkflowRunMessage::isResume} carries no workflow type
     * in the message (it is empty): the handler reads it back from the metadata.
     *
     * @param array<string, mixed> $entry one {@see DurableExecutionTrace} entry
     */
    public static function dispatchTimelineLabel(array $entry): string
    {
        $wt = trim((string) ($entry['workflowType'] ?? ''));
        $isResume = (bool) ($entry['isResume'] ?? false);
        $tn = (string) ($entry['transportNames'] ?? '');

        $parts = [];
        if ($isResume) {
            $parts[] = 'Messenger resume (WorkflowRunMessage)';
            $parts[] = 'no type in the message — resolved at the handler from the metadata';
        } elseif ('' !== $wt) {
            $parts[] = 'New run "' . $wt . '" (WorkflowRunMessage)';
        } else {
            $parts[] = 'WorkflowRunMessage (no type given in the message)';
        }
        if ('' !== $tn) {
            $parts[] = 'transports: ' . $tn;
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $entry one {@see DurableExecutionTrace} entry
     *
     * @return array{title: string, subtitle: string, category: string}
     */
    public static function fromProcessTrace(array $entry): array
    {
        $kind = (string) ($entry['kind'] ?? '');

        return match ($kind) {
            'dispatch' => [
                'title' => (bool) ($entry['isResume'] ?? false)
                    ? 'Messenger resume'
                    : ('' !== trim((string) ($entry['workflowType'] ?? ''))
                        ? 'New Messenger run'
                        : 'WorkflowRunMessage message'),
                'subtitle' => self::dispatchTimelineLabel($entry),
                'category' => 'messenger',
            ],
            'workflow' => [
                'title' => !empty($entry['isResume']) ? 'Workflow resumed' : 'Workflow started',
                'subtitle' => (string) ($entry['workflowType'] ?? ''),
                'category' => 'workflow',
            ],
            'activity' => [
                'title' => 'Activity execution',
                'subtitle' => \sprintf(
                    '%s · id %s%s',
                    $entry['activityName'] ?? '?',
                    $entry['activityId'] ?? '?',
                    empty($entry['success']) ? ' · failed' : '',
                ),
                'category' => 'activity',
            ],
            default => [
                'title' => '' !== $kind ? $kind : 'Event',
                'subtitle' => '',
                'category' => 'default',
            ],
        };
    }

    /**
     * @return array{title: string, subtitle: string, category: string, technical: string}
     */
    public static function fromStoreEvent(Event $event): array
    {
        $technical = (new \ReflectionClass($event))->getShortName();

        if ($event instanceof ExecutionStarted) {
            return [
                'title' => 'Execution started',
                'subtitle' => 'The engine replays the workflow from the journal (event sourcing).',
                'category' => 'lifecycle',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ExecutionCompleted) {
            return [
                'title' => 'Execution finished',
                'subtitle' => 'Final result written to the journal.',
                'category' => 'lifecycle',
                'technical' => $technical,
            ];
        }

        if ($event instanceof WorkflowExecutionFailed) {
            return [
                'title' => 'Workflow failed',
                'subtitle' => 'See the payload for the detail of the failure.',
                'category' => 'lifecycle',
                'technical' => $technical,
            ];
        }

        if ($event instanceof WorkflowContinuedAsNew) {
            return [
                'title' => 'Continue as new',
                'subtitle' => 'New execution: ' . $event->nextWorkflowType(),
                'category' => 'lifecycle',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ActivityScheduled) {
            return [
                'title' => 'Activity queued',
                'subtitle' => $event->activityName() . ' · id ' . $event->activityId(),
                'category' => 'scheduling',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ActivityCompleted) {
            return [
                'title' => 'Activity succeeded',
                'subtitle' => 'id ' . $event->activityId(),
                'category' => 'activity',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ActivityFailed) {
            $msg = $event->failureMessage();
            if (\strlen($msg) > 120) {
                $msg = substr($msg, 0, 117) . '…';
            }

            return [
                'title' => 'Activity failed',
                'subtitle' => ('' !== $event->activityName() ? $event->activityName() . ' · ' : '') . $msg,
                'category' => 'activity',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ActivityCancelled) {
            return [
                'title' => 'Activity cancelled',
                'subtitle' => 'id ' . $event->activityId() . ' · ' . $event->reason(),
                'category' => 'activity',
                'technical' => $technical,
            ];
        }

        if ($event instanceof TimerScheduled) {
            return [
                'title' => 'Timer scheduled',
                'subtitle' => 'id ' . $event->timerId() . ('' !== $event->summary() ? ' · ' . $event->summary() : ''),
                'category' => 'timer',
                'technical' => $technical,
            ];
        }

        if ($event instanceof TimerCompleted) {
            return [
                'title' => 'Timer fired',
                'subtitle' => 'id ' . $event->timerId(),
                'category' => 'timer',
                'technical' => $technical,
            ];
        }

        if ($event instanceof SideEffectRecorded) {
            return [
                'title' => 'Side effect recorded',
                'subtitle' => 'id ' . $event->sideEffectId(),
                'category' => 'effect',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ChildWorkflowScheduled) {
            return [
                'title' => 'Child workflow scheduled',
                'subtitle' => $event->childWorkflowType() . ' · enfant ' . $event->childExecutionId(),
                'category' => 'child',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ChildWorkflowCompleted) {
            return [
                'title' => 'Child workflow finished',
                'subtitle' => 'enfant ' . $event->childExecutionId(),
                'category' => 'child',
                'technical' => $technical,
            ];
        }

        if ($event instanceof WorkflowSignalReceived) {
            return [
                'title' => 'Signal received',
                'subtitle' => '"' . $event->signalName() . '"',
                'category' => 'signal',
                'technical' => $technical,
            ];
        }

        if ($event instanceof WorkflowUpdateHandled) {
            return [
                'title' => 'Update handled',
                'subtitle' => '"' . $event->updateName() . '"',
                'category' => 'signal',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ActivityTaskFailed) {
            return [
                'title' => $event->willRetry() ? 'Activity attempt failed' : 'Last activity attempt failed',
                'subtitle' => \sprintf(
                    '%s · attempt %d · %s',
                    $event->activityName(),
                    $event->attempt(),
                    $event->failureMessage(),
                ),
                'category' => 'activity',
                'technical' => $technical,
            ];
        }

        if ($event instanceof TimerCancelled) {
            return [
                'title' => 'Timer cancelled',
                'subtitle' => $event->reason(),
                'category' => 'activity',
                'technical' => $technical,
            ];
        }

        if ($event instanceof WorkflowExecutionCancelled) {
            return [
                'title' => 'Execution cancelled',
                'subtitle' => $event->reason(),
                'category' => 'lifecycle',
                'technical' => $technical,
            ];
        }

        if ($event instanceof WorkflowCancellationRequested) {
            return [
                'title' => 'Cancellation requested',
                'subtitle' => $event->reason(),
                'category' => 'lifecycle',
                'technical' => $technical,
            ];
        }

        return [
            'title' => $technical,
            'subtitle' => 'Journal event — see the payload for the detail.',
            'category' => 'default',
            'technical' => $technical,
        ];
    }
}
