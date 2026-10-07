<?php

declare(strict_types=1);

namespace unit\DurableLaravel;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\SystemClock;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use unit\Durable\Fixtures\FrozenClock;

/**
 * Laravel has no PSR-20 clock of its own: the provider binds `durable.clock` to the core's system
 * clock, and an application rebinds it to read time elsewhere (#617).
 */
final class TheProviderWiresTheClockTest extends TestCase
{
    public function testByDefaultTheRuntimeReadsTheSystemClock(): void
    {
        self::assertInstanceOf(SystemClock::class, $this->registered()->make(ExecutionRuntime::class)->clock());
    }

    public function testARebindingReachesEveryServiceThatReadsTime(): void
    {
        $clock = new FrozenClock(1_700_000_000.0);
        $app = $this->registered();
        $app->instance('durable.clock', $clock);

        self::assertSame($clock, $app->make(ExecutionRuntime::class)->clock());

        $transport = $app->make(ActivityTransportInterface::class);
        $transport->enqueue(new ActivityMessage('exec-1', 'act-1', 'charge', [], retryDelay: Duration::seconds(5.0)));
        self::assertSame(1_700_000_005.0, $transport->nextDueAt());

        $journal = $app->make(EventStoreInterface::class);
        $journal->append(new \Gplanchat\Durable\Event\WorkflowSignalReceived(ExecutionId::fromString('exec-1'), 'go', []));
        foreach ($journal->readStreamWithRecordedAt(ExecutionId::fromString('exec-1')) as $row) {
            self::assertSame('1700000000', $row['recordedAt']->format('U'));
        }
    }

    public function testTheClockIsBoundByItsInterfaceAndTheStringIdIsItsAlias(): void
    {
        // #879: durable-filament resolves the clock by class; the string id stays for compatibility.
        $app = $this->registered();

        self::assertInstanceOf(SystemClock::class, $app->make(ClockInterface::class));
        self::assertSame($app->make(ClockInterface::class), $app->make('durable.clock'));
    }

    public function testAClockBoundByItsInterfaceReachesTheRuntime(): void
    {
        $clock = new FrozenClock(1_700_000_000.0);
        $app = $this->registered();
        $app->instance(ClockInterface::class, $clock);

        self::assertSame($clock, $app->make(ExecutionRuntime::class)->clock());
    }

    public function testAClockBoundUnderTheStringIdBeforeTheProviderIsTheOneTheInterfaceResolves(): void
    {
        $clock = new FrozenClock(1_700_000_000.0);
        $app = new Container();
        $app->instance('config', new \ArrayObject(['durable' => ['backend' => 'memory']], \ArrayObject::ARRAY_AS_PROPS));
        $app->instance('durable.clock', $clock);
        (new DurableServiceProvider($app))->register();

        self::assertSame($clock, $app->make(ClockInterface::class));
        self::assertSame($clock, $app->make(ExecutionRuntime::class)->clock());
    }

    /**
     * Each route an application has to replace the clock, before or after the provider registers.
     * The runtime and the dashboard (which resolves `ClockInterface`) must read the same clock.
     *
     * @return iterable<string, array{list<array{string, string, string}>, list<array{string, string, string}>, ?string}>
     */
    public static function rebindingRoutes(): iterable
    {
        // [method, id, clock name] before the provider, the same after it, and the clock both read.
        yield 'nothing rebound' => [[], [], null];
        yield 'instance(durable.clock) after, the #617 route' => [[], [['instance', 'durable.clock', 'X']], 'X'];
        yield 'singleton(durable.clock) after' => [[], [['singleton', 'durable.clock', 'X']], 'X'];
        yield 'instance(ClockInterface) after' => [[], [['instance', ClockInterface::class, 'X']], 'X'];
        yield 'durable.clock before' => [[['instance', 'durable.clock', 'P']], [], 'P'];
        yield 'durable.clock before, ClockInterface after' => [[['instance', 'durable.clock', 'P']], [['instance', ClockInterface::class, 'X']], 'X'];
        yield 'durable.clock before, durable.clock after' => [[['instance', 'durable.clock', 'P']], [['instance', 'durable.clock', 'X']], 'X'];
        // The application owns the PSR-20 id: its clock drives Durable, and wins over durable.clock.
        yield 'ClockInterface before' => [[['instance', ClockInterface::class, 'Y']], [], 'Y'];
        yield 'ClockInterface before, durable.clock after' => [[['instance', ClockInterface::class, 'Y']], [['instance', 'durable.clock', 'X']], 'Y'];
        yield 'ClockInterface and durable.clock before' => [[['instance', ClockInterface::class, 'Y'], ['instance', 'durable.clock', 'P']], [], 'Y'];
        // The UPGRADE migration: ClockInterface delegates to durable.clock. Bound before the
        // provider with nothing under durable.clock, it must resolve, not recurse.
        yield 'delegate before, nothing else' => [[['delegate', ClockInterface::class, '']], [], null];
        yield 'delegate before, durable.clock after' => [[['delegate', ClockInterface::class, '']], [['instance', 'durable.clock', 'X']], 'X'];
    }

    /**
     * @param list<array{string, string, string}> $before
     * @param list<array{string, string, string}> $after
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rebindingRoutes')]
    public function testTheRuntimeAndTheDashboardReadTheSameClock(array $before, array $after, ?string $expected): void
    {
        $clocks = ['P' => new FrozenClock(1.0), 'X' => new FrozenClock(2.0), 'Y' => new FrozenClock(3.0)];
        $bind = static function (Container $app, array $steps) use ($clocks): void {
            foreach ($steps as [$method, $id, $name]) {
                match ($method) {
                    'instance' => $app->instance($id, $clocks[$name]),
                    'singleton' => $app->singleton($id, static fn() => $clocks[$name]),
                    'delegate' => $app->bind($id, static fn($app) => $app->make('durable.clock')),
                    default => throw new \LogicException(\sprintf('Unknown binding step "%s".', $method)),
                };
            }
        };
        $app = new Container();
        $app->instance('config', new \ArrayObject(['durable' => ['backend' => 'memory']], \ArrayObject::ARRAY_AS_PROPS));
        $bind($app, $before);
        (new DurableServiceProvider($app))->register();
        $bind($app, $after);

        $runtime = $app->make(ExecutionRuntime::class)->clock();
        if (null === $expected) {
            self::assertInstanceOf(SystemClock::class, $runtime);
        } else {
            self::assertSame($clocks[$expected], $runtime);
        }
        self::assertSame($runtime, $app->make(ClockInterface::class));
    }

    private function registered(): Container
    {
        $app = new Container();
        $app->instance('config', new \ArrayObject(['durable' => ['backend' => 'memory']], \ArrayObject::ARRAY_AS_PROPS));
        (new DurableServiceProvider($app))->register();

        return $app;
    }
}
