<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Workflow\WorkflowDescriber;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\ShippingWorkflow;

/**
 * What a developer reads in `debug:durable`: a workflow's type, entry point and handlers, from its attributes alone.
 */
final class WorkflowDescriberTest extends TestCase
{
    public function testTheTypeIsTheNameOfTheAsWorkflowAttribute(): void
    {
        $description = WorkflowDescriber::describe(ShippingWorkflow::class);

        self::assertSame('shipping', $description->type);
        self::assertSame(ShippingWorkflow::class, $description->class);
    }

    public function testTheEntryPointListsWhatTheCallerPassesAndNotTheEnvironment(): void
    {
        $entry = WorkflowDescriber::describe(ShippingWorkflow::class)->entry;

        self::assertSame('ship', $entry->method);
        self::assertSame('ship(string $orderId, int $parcels = …): array', $entry->signature());
    }

    public function testSignalsQueriesAndUpdatesAreListedByTheirWireName(): void
    {
        $description = WorkflowDescriber::describe(ShippingWorkflow::class);

        self::assertSame(['parcel.picked'], array_keys($description->signals));
        self::assertSame('onParcelPicked(string $carrier): void', $description->signals['parcel.picked']->signature());
        self::assertSame('status(): string', $description->queries['status']->signature());
        self::assertSame('changeAddress(string $street, string $city): bool', $description->updates['change-address']->signature());
    }
}
