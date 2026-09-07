<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Versioning;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Versioning\ChangePoint;
use PHPUnit\Framework\TestCase;

/**
 * The execution that went past this point **before it existed**.
 *
 * This is the case versioning is invented for, and the only one the primitive did not cover. Its
 * journal carries no marker, because at the moment it went through there was nothing to mark.
 * Giving it the new behaviour would be the exact opposite of what is wanted: it started on the old
 * one, it has to finish on the old one.
 *
 * The distinction turns on a single question — does the journal still carry work this pass has
 * not reached? If so, the call is in the replayed prefix and the execution is older than the
 * change point. Otherwise, it is reaching it for the first time.
 */
final class ChangePointOnAnOlderRunTest extends TestCase
{
    private const EXECUTION = 'exec-older';

    public function testARunThatPredatesTheChangePointKeepsTheOldBehaviour(): void
    {
        // Two activities already recorded: the code of the time declared no change point.
        // Today's code declares one BEFORE them.
        $context = $this->contextWithTwoRecordedActivities();

        $version = $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(
            ChangePoint::DEFAULT_VERSION,
            $version,
            'an execution started before the change point keeps the old behaviour',
        );
    }

    public function testItIsNotMarkedEither(): void
    {
        $store = new InMemoryEventStore();
        $this->seedTwoActivities($store);
        $before = iterator_count($store->readStream(self::EXECUTION));

        $this->context($store)->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(
            $before,
            iterator_count($store->readStream(self::EXECUTION)),
            'nothing is written: the answer is deduced from the history, it is not added to it',
        );
    }

    public function testTheAnswerIsStableAcrossReplays(): void
    {
        $store = new InMemoryEventStore();
        $this->seedTwoActivities($store);

        $first = $this->context($store)->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);
        $second = $this->context($store)->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(ChangePoint::DEFAULT_VERSION, $first);
        self::assertSame($first, $second, 'deducible from the history, therefore stable by construction');
    }

    public function testAPointReachedPastTheRecordedWorkIsNew(): void
    {
        // The same execution, but the change point is placed AFTER its recorded work: it reaches
        // it for the first time now, so it takes the new one.
        $context = $this->contextWithTwoRecordedActivities();
        $context->activity('chargeCard', []);
        $context->activity('shipOrder', []);

        $version = $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(1, $version, 'past the recorded work, the point is new to it');
    }

    public function testAFreshRunIsNotMistakenForAnOldOne(): void
    {
        $version = $this->context(new InMemoryEventStore())
            ->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(1, $version, 'an empty journal is not a replayed prefix');
    }

    private function seedTwoActivities(InMemoryEventStore $store): void
    {
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'chargeCard', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 42));
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-2', 'shipOrder', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-2', 'shipped'));
    }

    private function contextWithTwoRecordedActivities(): ExecutionContext
    {
        $store = new InMemoryEventStore();
        $this->seedTwoActivities($store);

        return $this->context($store);
    }

    private function context(InMemoryEventStore $store): ExecutionContext
    {
        return new ExecutionContext(
            self::EXECUTION,
            new EventStoreHistorySource($store, self::EXECUTION),
            new EventStoreCommandBuffer($store, new NoopActivityTransport(), self::EXECUTION),
        );
    }
}
