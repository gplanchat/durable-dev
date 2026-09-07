<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Replay;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use PHPUnit\Framework\TestCase;

/**
 * The journal says A was called; the deployed code asks for B at the same position.
 *
 * Measured before it was fixed (probe 1.1 of `workflow-replay-divergence-guard`, `start-dev`
 * server 1.31.2): the slot resolved **silently** with the recorded result of the other call, and
 * the execution ended **successfully** carrying it. Nothing in the history marked the divergence,
 * and no later moment noticed it.
 *
 * DUR003 nonetheless described this comparison as existing. It did not exist.
 */
final class ActivitySlotDivergenceTest extends TestCase
{
    private const EXECUTION = 'exec-divergence';

    public function testASwappedActivityIsRefusedInsteadOfResolved(): void
    {
        $context = $this->contextReplaying('chargeCard', 'act-1', 42);

        $this->expectException(WorkflowTaskFailure::class);
        $context->activity('reserveStock', ['sku' => 'ABC']);
    }

    public function testTheRefusalNamesBothSidesAndWhereItHappened(): void
    {
        $context = $this->contextReplaying('chargeCard', 'act-1', 42);

        try {
            $context->activity('reserveStock', ['sku' => 'ABC']);
            self::fail('The divergence should have been refused.');
        } catch (WorkflowTaskFailure $e) {
            $message = $e->getMessage();
        }

        // Without these three, the message costs a bisect to whoever reads it.
        self::assertStringContainsString('chargeCard', $message, 'The recorded name is missing: we do not know what the history holds.');
        self::assertStringContainsString('reserveStock', $message, 'The requested name is missing: we do not know what the code wanted.');
        self::assertStringContainsString(self::EXECUTION, $message, 'The execution is missing: the first thing one does is open its history.');
    }

    public function testTheMessageCarriesTheFivePartsNeededToAct(): void
    {
        // What someone does with this message, in order: they open the history of that
        // execution, go to that slot, and compare the two names. The five parts are therefore a
        // contract, not a wording — that is why they have their own test.
        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'chargeCard', ['sku' => 'ABC']));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 42));
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-2', 'shipOrder', ['sku' => 'ABC']));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-2', 'shipped'));

        $context = new ExecutionContext(
            self::EXECUTION,
            new EventStoreHistorySource($store, self::EXECUTION),
            new EventStoreCommandBuffer($store, new NoopActivityTransport(), self::EXECUTION),
        );
        $context->activity('chargeCard', ['sku' => 'ABC']);

        try {
            $context->activity('reserveStock', ['sku' => 'ABC']);
            self::fail('The divergence should have been refused.');
        } catch (WorkflowTaskFailure $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('activity', $message, 'the slot kind');
        self::assertStringContainsString('slot 1', $message, "the index, and the second call's at that — not 0 by accident");
        self::assertStringContainsString(self::EXECUTION, $message, 'the execution');
        self::assertStringContainsString('"shipOrder"', $message, 'what the journal holds at THAT slot');
        self::assertStringContainsString('"reserveStock"', $message, 'what the code asked for');
    }

    public function testAnUnchangedActivityStillResolvesFromHistory(): void
    {
        $context = $this->contextReplaying('chargeCard', 'act-1', 42);

        $awaitable = $context->activity('chargeCard', ['sku' => 'ABC']);

        self::assertTrue($awaitable->isSettled(), 'The recorded slot must still resolve: the guard must cost the normal case nothing.');
    }

    public function testASlotNobodyRecordedIsNotADivergence(): void
    {
        // Second call of the workflow, first pass: nothing at that position, so nothing to
        // compare. A guard that refused here would break every workflow that grows.
        $context = $this->contextReplaying('chargeCard', 'act-1', 42);
        $context->activity('chargeCard', ['sku' => 'ABC']);

        $second = $context->activity('shipOrder', ['sku' => 'ABC']);

        self::assertFalse($second->isSettled(), 'A fresh slot is scheduled, not refused.');
    }

    public function testAnUnrecordedNameIsNotADivergence(): void
    {
        // A history that does not carry the activity name says nothing about that slot. The
        // guard cannot compare, so it lets it through: this is the announced hole, not a refusal.
        // Without this rule, the guard would fire on every slot whose identity is missing —
        // exactly the opposite of what is asked of it.
        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', '', ['sku' => 'ABC']));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 42));

        $context = new ExecutionContext(
            self::EXECUTION,
            new EventStoreHistorySource($store, self::EXECUTION),
            new EventStoreCommandBuffer($store, new NoopActivityTransport(), self::EXECUTION),
        );

        $awaitable = $context->activity('reserveStock', ['sku' => 'ABC']);

        self::assertTrue($awaitable->isSettled(), 'With no recorded identity, the slot resolves as before.');
    }

    private function contextReplaying(string $recordedName, string $activityId, mixed $result): ExecutionContext
    {
        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled(self::EXECUTION, $activityId, $recordedName, ['sku' => 'ABC']));
        $store->append(new ActivityCompleted(self::EXECUTION, $activityId, $result));

        return new ExecutionContext(
            self::EXECUTION,
            new EventStoreHistorySource($store, self::EXECUTION),
            new EventStoreCommandBuffer($store, new NoopActivityTransport(), self::EXECUTION),
        );
    }
}
