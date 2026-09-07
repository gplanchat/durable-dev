<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Store\TemporalRunHistoryReader;
use PHPUnit\Framework\TestCase;

/**
 * The execution takes one line, and **what is not part of it is the bulk of the rule**.
 *
 * `WORKFLOW_EXECUTION_SIGNALED` and the `WORKFLOW_EXECUTION_UPDATE_*` family start with the same
 * prefix as the execution without belonging to it: a received signal and an update are waits in
 * their own right. Child and external workflows do not start with that prefix, and that is all
 * that leaves them their lines.
 *
 * The split is therefore proven **type by type**, from the server's enumeration and not from
 * memory: that is what stops a later version from silently folding a signal into the first line,
 * where nobody would look for it.
 */
final class TheRunIsOneLineAndItsExceptionsAreTheRuleTest extends TestCase
{
    /** @var list<string> */
    private const THE_RUN_ITSELF = [
        'EVENT_TYPE_WORKFLOW_EXECUTION_STARTED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_FAILED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_TIMED_OUT',
        'EVENT_TYPE_WORKFLOW_EXECUTION_CANCELED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_CANCEL_REQUESTED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_TERMINATED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_CONTINUED_AS_NEW',
        'EVENT_TYPE_WORKFLOW_EXECUTION_OPTIONS_UPDATED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_PAUSED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_UNPAUSED',
        'EVENT_TYPE_WORKFLOW_TASK_SCHEDULED',
        'EVENT_TYPE_WORKFLOW_TASK_STARTED',
        'EVENT_TYPE_WORKFLOW_TASK_COMPLETED',
        'EVENT_TYPE_WORKFLOW_TASK_FAILED',
        'EVENT_TYPE_WORKFLOW_TASK_TIMED_OUT',
    ];

    /** @var list<string> */
    private const A_LINE_OF_ITS_OWN = [
        // The same prefix, and yet not the execution.
        'EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ADMITTED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ACCEPTED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_COMPLETED',
        'EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_REJECTED',
        // The children: they are the ones the author expressly asked not to fold.
        'EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_INITIATED',
        'EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_FAILED',
        'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_STARTED',
        'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_COMPLETED',
        'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_FAILED',
        'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_CANCELED',
        'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_TIMED_OUT',
        'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_TERMINATED',
        // The workflows on the other side.
        'EVENT_TYPE_EXTERNAL_WORKFLOW_EXECUTION_SIGNALED',
        'EVENT_TYPE_EXTERNAL_WORKFLOW_EXECUTION_CANCEL_REQUESTED',
        'EVENT_TYPE_SIGNAL_EXTERNAL_WORKFLOW_EXECUTION_INITIATED',
        'EVENT_TYPE_SIGNAL_EXTERNAL_WORKFLOW_EXECUTION_FAILED',
        'EVENT_TYPE_REQUEST_CANCEL_EXTERNAL_WORKFLOW_EXECUTION_INITIATED',
        'EVENT_TYPE_REQUEST_CANCEL_EXTERNAL_WORKFLOW_EXECUTION_FAILED',
        // And the rest, each occurrence of which is a fact the operator reads in its own right.
        'EVENT_TYPE_ACTIVITY_TASK_SCHEDULED',
        'EVENT_TYPE_ACTIVITY_TASK_STARTED',
        'EVENT_TYPE_ACTIVITY_TASK_COMPLETED',
        'EVENT_TYPE_ACTIVITY_TASK_FAILED',
        'EVENT_TYPE_ACTIVITY_TASK_TIMED_OUT',
        'EVENT_TYPE_ACTIVITY_TASK_CANCEL_REQUESTED',
        'EVENT_TYPE_ACTIVITY_TASK_CANCELED',
        'EVENT_TYPE_ACTIVITY_PROPERTIES_MODIFIED_EXTERNALLY',
        'EVENT_TYPE_TIMER_STARTED',
        'EVENT_TYPE_TIMER_FIRED',
        'EVENT_TYPE_TIMER_CANCELED',
        'EVENT_TYPE_NEXUS_OPERATION_SCHEDULED',
        'EVENT_TYPE_NEXUS_OPERATION_STARTED',
        'EVENT_TYPE_NEXUS_OPERATION_COMPLETED',
        'EVENT_TYPE_NEXUS_OPERATION_FAILED',
        'EVENT_TYPE_NEXUS_OPERATION_CANCELED',
        'EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT',
        'EVENT_TYPE_NEXUS_OPERATION_CANCEL_REQUESTED',
        'EVENT_TYPE_NEXUS_OPERATION_CANCEL_REQUEST_COMPLETED',
        'EVENT_TYPE_NEXUS_OPERATION_CANCEL_REQUEST_FAILED',
        'EVENT_TYPE_MARKER_RECORDED',
        'EVENT_TYPE_UPSERT_WORKFLOW_SEARCH_ATTRIBUTES',
        'EVENT_TYPE_WORKFLOW_PROPERTIES_MODIFIED',
        'EVENT_TYPE_WORKFLOW_PROPERTIES_MODIFIED_EXTERNALLY',
    ];

    public function testEveryEventOfTheRunItselfFoldsIntoTheFirstLine(): void
    {
        foreach (self::THE_RUN_ITSELF as $eventType) {
            self::assertTrue($this->belongs($eventType), $eventType . ' belongs to the execution');
        }
    }

    public function testEverythingElseKeepsItsOwnLine(): void
    {
        foreach (self::A_LINE_OF_ITS_OWN as $eventType) {
            self::assertFalse($this->belongs($eventType), $eventType . ' keeps its line');
        }
    }

    public function testTheTwoListsCoverEveryTypeTheServerCanSend(): void
    {
        // A type the split does not name is a type whose line nobody has decided. The test says
        // so here rather than leaving an operator to discover it in a frieze.
        $declared = array_merge(self::THE_RUN_ITSELF, self::A_LINE_OF_ITS_OWN);

        $missing = array_diff($this->everyEventTypeTheServerDeclares(), $declared);

        self::assertSame([], array_values($missing), 'unfiled types: ' . implode(', ', $missing));
    }

    public function testEveryFamilyOfFailureIsMarked(): void
    {
        // One failure per level: if a single suffix were missing from the rule, a whole family
        // would come out of the page in black although it went wrong.
        $failures = [
            'EVENT_TYPE_ACTIVITY_TASK_FAILED',
            'EVENT_TYPE_ACTIVITY_TASK_TIMED_OUT',
            'EVENT_TYPE_WORKFLOW_EXECUTION_FAILED',
            'EVENT_TYPE_WORKFLOW_EXECUTION_TIMED_OUT',
            'EVENT_TYPE_WORKFLOW_TASK_FAILED',
            'EVENT_TYPE_WORKFLOW_TASK_TIMED_OUT',
            'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_FAILED',
            'EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_TIMED_OUT',
            'EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_FAILED',
            'EVENT_TYPE_SIGNAL_EXTERNAL_WORKFLOW_EXECUTION_FAILED',
            'EVENT_TYPE_NEXUS_OPERATION_FAILED',
            'EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT',
        ];

        foreach ($failures as $eventType) {
            self::assertTrue($this->isFailure($eventType), $eventType . ' went wrong');
        }
    }

    public function testNoCancellationIsPaintedAsAFailure(): void
    {
        // A cancellation is an outcome, not a breakdown. The list comes from the server's
        // enumeration and not from memory: a cancellation type added later falls into this test on
        // its own, and that is the only way for red to keep meaning something.
        $outcomes = array_values(array_filter(
            $this->everyEventTypeTheServerDeclares(),
            static fn(string $type): bool => str_ends_with($type, '_CANCELED')
                || str_ends_with($type, '_CANCEL_REQUESTED')
                || str_ends_with($type, '_CANCEL_REQUEST_COMPLETED')
                || str_ends_with($type, '_TERMINATED'),
        ));

        self::assertNotSame([], $outcomes, 'the server enumeration must declare some');

        foreach ($outcomes as $eventType) {
            self::assertFalse($this->isFailure($eventType), $eventType . ' is an outcome, not a breakdown');
        }
    }

    public function testACancellationThatCouldNotBeDeliveredIsAFailure(): void
    {
        // The trap the previous test found: those two types speak of cancellation and yet end in
        // `_FAILED`. It is not the cancellation that is a breakdown, it is the cancellation
        // request that **did not get through** — the targeted execution keeps running while
        // somebody has asked for it to stop, and that is exactly the kind of fact one does not
        // want to see come out in black.
        self::assertTrue($this->isFailure('EVENT_TYPE_REQUEST_CANCEL_EXTERNAL_WORKFLOW_EXECUTION_FAILED'));
        self::assertTrue($this->isFailure('EVENT_TYPE_NEXUS_OPERATION_CANCEL_REQUEST_FAILED'));
    }

    private function isFailure(string $eventType): bool
    {
        $rule = new \ReflectionMethod(TemporalRunHistoryReader::class, 'isFailure');
        $rule->setAccessible(true);

        return (bool) $rule->invoke(null, $eventType);
    }

    private function belongs(string $eventType): bool
    {
        $rule = new \ReflectionMethod(TemporalRunHistoryReader::class, 'belongsToTheRunItself');
        $rule->setAccessible(true);

        return (bool) $rule->invoke(null, $eventType);
    }

    /**
     * @return list<string>
     */
    private function everyEventTypeTheServerDeclares(): array
    {
        $names = [];
        foreach ((new \ReflectionClass(\Temporal\Api\Enums\V1\EventType::class))->getConstants() as $name => $value) {
            if ('EVENT_TYPE_UNSPECIFIED' !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
