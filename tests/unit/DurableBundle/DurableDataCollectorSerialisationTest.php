<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A workflow payload is business data: anything can be in there.
 *
 * The profiler, for its part, must be **storable** — the `Profiler` serialises the whole profile
 * to write it. A value that does not survive `serialize()` inside a payload therefore does not
 * break the Durable panel: it breaks the profile of the request, the other bundles' panels
 * included.
 *
 * Hence the contract of these cases: what the collector stores must always be serialisable,
 * whatever it is given to observe.
 */
final class DurableDataCollectorSerialisationTest extends TestCase
{
    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function valuesThatDoNotSerialise(): iterable
    {
        yield 'closure' => [static fn(): int => 1];
        yield 'resource' => [fopen('php://memory', 'rb')];
        yield 'anonymous object carrying a closure' => [new class {
            public \Closure $callback;

            public function __construct()
            {
                $this->callback = static fn(): int => 1;
            }
        }];
    }

    #[DataProvider('valuesThatDoNotSerialise')]
    public function testTheProfileStaysStorableWhateverThePayloadCarries(mixed $value): void
    {
        $collector = self::collectorHavingObserved(['order' => 'X-1', 'hostile' => $value]);

        $serialised = serialize($collector);

        self::assertIsString($serialised);
        self::assertInstanceOf(DurableDataCollector::class, unserialize($serialised));
    }

    /**
     * The rest of the payload is what the operator came to read; a value that cannot be rendered
     * must not take it away with it.
     */
    public function testWhatIsReadableInThePayloadIsKept(): void
    {
        $collector = self::collectorHavingObserved([
            'order' => 'X-1',
            'amount' => 1250,
            'hostile' => static fn(): int => 1,
        ]);

        $rendered = json_encode(unserialize(serialize($collector))->getTimeline());

        self::assertStringContainsString('X-1', (string) $rendered);
        self::assertStringContainsString('1250', (string) $rendered);
    }

    /**
     * The journal may carry bytes that are not valid text. `json_encode` then returns `false`,
     * and the template shows a blank where there was a payload.
     */
    public function testABinaryPayloadStaysDisplayable(): void
    {
        $collector = self::collectorHavingObserved(['blob' => "\xB1\x31\xFE"]);

        $rendered = json_encode(unserialize(serialize($collector))->getTimeline());

        self::assertIsString($rendered, 'a binary payload must not leave the panel blank');
    }

    /**
     * The barrier must not distort anything that already went through: the template reads precise
     * keys, and a JSON round trip that turned a list into an object would break them silently.
     */
    public function testAnOrdinaryPayloadGoesThroughUndistorted(): void
    {
        $payload = [
            'order' => 'X-1',
            'amount' => 1250,
            'discount' => 0.15,
            'urgent' => false,
            'lines' => ['a', 'b'],
            'customer' => ['id' => 7, 'name' => 'Smith'],
            'note' => null,
        ];

        $timeline = self::collectorHavingObserved($payload)->getTimeline();

        self::assertSame($payload, $timeline[0]['payload'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function collectorHavingObserved(array $payload): DurableDataCollector
    {
        $trace = new DurableExecutionTrace();
        $trace->onWorkflowDispatchRequested(ExecutionId::fromString('exec-1'), 'Order', $payload, false, 'async');

        $collector = new DurableDataCollector($trace, new InMemoryWorkflowMetadataStore(), new InMemoryEventStore());
        $collector->collect(new Request(), new Response());

        return $collector;
    }
}
