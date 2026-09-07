<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\Store\ActivityEventJournal;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A **retry** is not a **redelivery**, and confusing the two cost the Temporal backend every one
 * of its activity retries.
 *
 * Before processing, the worker asks the journal whether the task it has just received has already
 * been settled — so as not to re-execute a task the server redelivers after a lost answer. Asked
 * without the attempt number, the question handed the first attempt's failure to all the later
 * ones: three attempts burnt in two seconds, the same failure message copied over, and the
 * activity code called exactly once.
 *
 * What separates the two cases is not the nature of the outcome, it is **the attempt that wrote
 * it**.
 */
final class ARetryIsNotARedeliveryTest extends TestCase
{
    public function testAFailureFromAnEarlierAttemptDoesNotSettleThisOne(): void
    {
        // The case of issue #218: attempt 1 failed and its retry is scheduled. Attempt 2 must
        // **run**, not be answered with the failure of the previous one.
        $store = $this->journalWith($this->failedOnAttempt(1, ActivityRetryState::InProgress));

        self::assertNull($this->settledFor($store, attempt: 2));
    }

    public function testAFailureFromThisVeryAttemptSettlesIt(): void
    {
        // The guard's reason for existing, which has to survive the fix: the server redelivers
        // the same attempt because the worker's answer was lost. Re-executing it would replay a
        // side effect already produced.
        $store = $this->journalWith($this->failedOnAttempt(2, ActivityRetryState::InProgress));

        self::assertInstanceOf(ActivityFailed::class, $this->settledFor($store, attempt: 2));
    }

    public function testAnActivityThatSucceededIsSettledWhateverTheAttempt(): void
    {
        // A finished activity is not replayed: the attempt number does not come into it.
        $store = $this->journalWith(new ActivityCompleted('exec-1', 'act-1', 'receipt'));

        self::assertInstanceOf(ActivityCompleted::class, $this->settledFor($store, attempt: 7));
    }

    public function testAFailureWithoutARetryStateStaysSettled(): void
    {
        // `null` is a journal older than the discriminant, not a retry in flight. When in doubt,
        // one does not replay a side effect — the opposite would make the fix more dangerous than
        // the defect it fixes.
        $store = $this->journalWith($this->failedOnAttempt(1, null));

        self::assertInstanceOf(ActivityFailed::class, $this->settledFor($store, attempt: 2));
    }

    public function testATerminalFailureStaysSettledForEveryLaterAttempt(): void
    {
        $store = $this->journalWith($this->failedOnAttempt(1, ActivityRetryState::NonRetryableFailure));

        self::assertInstanceOf(ActivityFailed::class, $this->settledFor($store, attempt: 2));
    }

    private function failedOnAttempt(int $attempt, ?ActivityRetryState $retryState): ActivityFailed
    {
        return new ActivityFailed(
            'exec-1',
            'act-1',
            \RuntimeException::class,
            'the payment gateway did not answer',
            activityName: 'charge',
            failureAttempt: $attempt,
            retryState: $retryState,
        );
    }

    private function journalWith(\Gplanchat\Durable\Event\Event $event): InMemoryEventStore
    {
        $store = new InMemoryEventStore();
        $store->append($event);

        return $store;
    }

    private function settledFor(InMemoryEventStore $store, int $attempt): ?object
    {
        return ActivityEventJournal::settledOutcomeForDelivery($store, 'exec-1', 'act-1', $attempt);
    }
}
