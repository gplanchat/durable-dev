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
 * The divergence guard compared the activity **name** against the slot, and nothing else.
 *
 * A payload recomputed differently on replay therefore went through silently: the journal served
 * the old result, the fresh payload went to the bin, and the execution completed successfully
 * having lied about what it had asked for. This file holds both halves of the rule — what the
 * guard must refuse, and everything it must keep letting through, because a guard that stops a
 * healthy execution costs more than the hole it plugs.
 */
final class ActivityPayloadDivergenceTest extends TestCase
{
    private const EXECUTION = 'exec-payload-guard';

    public function testTheSameActivityWithAnotherPayloadIsRefused(): void
    {
        $store = $this->journalWith(['city' => 'Paris']);
        $context = $this->context($store);

        $this->expectException(WorkflowTaskFailure::class);
        $this->expectExceptionMessageMatches('/payload changed/');

        $context->activity('weather', ['city' => 'Lyon']);
    }

    public function testTheMessageNamesTheSlotTheActivityAndTheCause(): void
    {
        $store = $this->journalWith(['nonce' => 1]);
        $context = $this->context($store);

        try {
            $context->activity('weather', ['nonce' => 2]);
            self::fail('The guard should have refused this payload.');
        } catch (WorkflowTaskFailure $refusal) {
            $message = $refusal->getMessage();
            self::assertStringContainsString('activity slot 0', $message);
            self::assertStringContainsString('"weather"', $message, 'the name matches: saying so saves looking for a shifted slot');
            self::assertStringContainsString('non-deterministic workflow code', $message);
            self::assertStringNotContainsString('different version of the workflow', $message, 'that message would send the reader to ChangePoint, which can do nothing about it');
        }
    }

    public function testAFaithfulReplayPasses(): void
    {
        $store = $this->journalWith(['city' => 'Paris']);
        $context = $this->context($store);

        $awaitable = $context->activity('weather', ['city' => 'Paris']);

        self::assertTrue($awaitable->isSettled(), 'the slot resolves: the payload is the journal\'s');
    }

    public function testKeyOrderIsNotADivergence(): void
    {
        // A JSON object has no order. Seeing it change proves nothing, and treating it as a
        // divergence would stop executions whose payload is identical.
        $store = $this->journalWith(['b' => 2, 'a' => 1]);
        $context = $this->context($store);

        $awaitable = $context->activity('weather', ['a' => 1, 'b' => 2]);

        self::assertTrue($awaitable->isSettled());
    }

    public function testAListThatChangedOrderIsADivergence(): void
    {
        // In a list, the order *is* the information.
        $store = $this->journalWith(['cities' => ['Paris', 'Lyon']]);
        $context = $this->context($store);

        $this->expectException(WorkflowTaskFailure::class);

        $context->activity('weather', ['cities' => ['Lyon', 'Paris']]);
    }

    public function testAnEmptyRecordedPayloadIsStillCompared(): void
    {
        // `[]` is an activity scheduled without arguments, not "nothing recorded". Confusing it
        // with null is the trap of findSideEffectForSlot(), and it is not repeated here.
        $store = $this->journalWith([]);
        $context = $this->context($store);

        $this->expectException(WorkflowTaskFailure::class);

        $context->activity('weather', ['city' => 'Paris']);
    }

    public function testAnObjectTheJournalCannotSeeIntoDoesNotDiverge(): void
    {
        // The house style: read-only DTOs with private properties. The journal keeps nothing of
        // them — `{}` on the way out, `[]` on the way back. Without normalising both sides, every
        // execution carrying one would diverge on each resumption. That is the false positive that
        // would have stopped healthy workflows, and it is measured here so it does not come back.
        $freshPayload = [
            'amount' => new class (90, 'EUR') {
                public function __construct(private int $cents, private string $currency) {}
            },
            'ref' => 'A-1',
        ];

        // The round trip is not assumed, it is performed: what `DbalEventStore` writes, then what
        // `EventDataMapper` reads back. Hard-coding its result would let this test pass the day the
        // flattening changed — that is, the day the false positive came back.
        $throughTheDatabase = json_decode(
            json_encode(
                (new ActivityScheduled(self::EXECUTION, 'act-1', 'charge', $freshPayload))->payload(),
                \JSON_THROW_ON_ERROR,
            ),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        )['payload'];

        self::assertNotSame($freshPayload, $throughTheDatabase, 'without flattening, this test proves nothing');

        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'charge', $throughTheDatabase));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 'ok'));

        $context = $this->context($store);

        // What the code recomputes on replay: the object, still alive.
        $awaitable = $context->activity('charge', $freshPayload);

        self::assertTrue($awaitable->isSettled(), 'a faithful replay must not die on an opaque object');
    }

    public function testAnIncomparablePayloadWaivesTheGuard(): void
    {
        // A resource cannot be encoded. The guard then has nothing to compare: it stays silent
        // rather than accuse a payload it cannot read.
        $store = $this->journalWith(['handle' => null]);
        $context = $this->context($store);

        $handle = fopen('php://memory', 'r');
        self::assertIsResource($handle);

        $awaitable = $context->activity('weather', ['handle' => $handle]);

        self::assertTrue($awaitable->isSettled());
        fclose($handle);
    }

    public function testAHistoryWithoutARecordedSlotIsNotADivergence(): void
    {
        // A slot nobody recorded is a workflow that grows — not a divergence.
        $context = $this->context(new InMemoryEventStore());

        $awaitable = $context->activity('weather', ['city' => 'Paris']);

        self::assertFalse($awaitable->isSettled(), 'the slot is new: it goes to scheduling');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function journalWith(array $payload): InMemoryEventStore
    {
        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'weather', $payload));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', '22°C'));

        return $store;
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
