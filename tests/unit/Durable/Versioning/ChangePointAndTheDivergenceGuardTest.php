<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Versioning;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\VersionMarked;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Versioning\ChangePoint;
use PHPUnit\Framework\TestCase;

/**
 * Versioning is the sanctioned exception to the divergence guard (DUR042) — and an exception that
 * would disarm the rule would be worth less than no exception at all.
 *
 * The two mechanisms meet in the same place: the guard compares the identity at the slot, and a
 * change point is precisely what makes what the code asks of the following slots vary. This file
 * holds the three facts that make the cohabitation safe.
 */
final class ChangePointAndTheDivergenceGuardTest extends TestCase
{
    private const EXECUTION = 'exec-guard-version';

    public function testTheGuardDoesNotFireOnABranchTheVersionDecided(): void
    {
        // The execution is on version 1: its journal carries `discountedCharge` at slot 0,
        // and that is exactly what the v1 branch of the code asks for. No divergence.
        $store = new InMemoryEventStore();
        $store->append(new VersionMarked(self::EXECUTION, 'ajout-remise', 1));
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'discountedCharge', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 90));

        $context = $this->context($store);
        $version = $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(1, $version);

        // The branch this version commands. The guard must not flinch.
        $awaitable = 1 === $version
            ? $context->activity('discountedCharge', [])
            : $context->activity('plainCharge', []);

        self::assertTrue($awaitable->isSettled(), 'the slot resolves: the code followed its version');
    }

    public function testAnOldRunTakesTheOtherBranchWithoutDivergingEither(): void
    {
        // The same execution, but started before the point: it has `plainCharge` at slot 0, and
        // the default branch asks for exactly that. Both branches are therefore legitimate —
        // each for the execution it concerns.
        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'plainCharge', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 100));
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-2', 'shipOrder', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-2', 'shipped'));

        $context = $this->context($store);
        $version = $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(ChangePoint::DEFAULT_VERSION, $version);

        $awaitable = ChangePoint::DEFAULT_VERSION === $version
            ? $context->activity('plainCharge', [])
            : $context->activity('discountedCharge', []);

        self::assertTrue($awaitable->isSettled());
    }

    public function testVersioningOnePointDoesNotDisarmTheGuardElsewhere(): void
    {
        // The change point is honoured, and ANOTHER slot is changed without declaring it. That
        // is where the value of the exception is decided: it covers only what it names.
        $store = new InMemoryEventStore();
        $store->append(new VersionMarked(self::EXECUTION, 'ajout-remise', 1));
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'discountedCharge', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 90));
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-2', 'shipOrder', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-2', 'shipped'));

        $context = $this->context($store);
        $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);
        $context->activity('discountedCharge', []);

        $this->expectException(WorkflowTaskFailure::class);
        $context->activity('sendInvoice', []); // the journal holds "shipOrder"
    }

    public function testTheVersionIsDecidedBeforeTheSlotsItCommands(): void
    {
        // Order is the heart of the matter: if the version were resolved AFTER the slot it
        // decides, the execution would already have used an answer it did not have.
        // The marker must therefore precede, in the journal, the activity it commands.
        $store = new InMemoryEventStore();
        $context = $this->context($store);

        $version = $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);
        $context->activity(1 === $version ? 'discountedCharge' : 'plainCharge', []);

        $kinds = array_map(
            static fn(object $e): string => $e::class,
            iterator_to_array($store->readStream(self::EXECUTION)),
        );
        $marker = array_search(VersionMarked::class, $kinds, true);
        $scheduled = array_search(ActivityScheduled::class, $kinds, true);

        self::assertIsInt($marker, 'the marker is written');
        self::assertIsInt($scheduled, 'the activity is scheduled');
        self::assertLessThan(
            $scheduled,
            $marker,
            'the version is decided and recorded BEFORE the slot it commands',
        );
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
