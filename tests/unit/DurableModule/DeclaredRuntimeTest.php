<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableModule\Runtime\UndeclaredWorkflowException;
use PHPUnit\Framework\TestCase;
use unit\DurableModule\Fixture\OrderWorkflow;
use unit\DurableModule\Fixture\RecordingOrderActivities;

/**
 * The declaration, since Magento's container has none of Symfony's tags.
 *
 * What is proved here without Magento: that a **declared** class runs, that its activities are
 * resolved from `#[AsActivityMethod]` and not from strings copied out by hand, and that an
 * **undeclared** class fails saying so. The factory is ordinary PHP — that is what lets CI guard
 * this mechanism, where the rest of the module asks for a bench.
 */
final class DeclaredRuntimeTest extends TestCase
{
    public function testADeclaredWorkflowRunsWithActivitiesResolvedFromTheirAttribute(): void
    {
        $runtime = (new RuntimeFactory(
            workflowClasses: [OrderWorkflow::class],
            activityHandlers: [new RecordingOrderActivities()],
        ))->create();

        self::assertSame('notify:charge:ORD-4242', $runtime->run(OrderWorkflow::class, ['orderId' => 'ORD-4242']));
    }

    /**
     * The names come from the contract, not from the demonstration command: that is the only
     * thing that tells a declaration mechanism apart from a list copied out beside it.
     */
    public function testTheDeclaredActivityNamesAreTheOnesTheContractCarries(): void
    {
        $runtime = (new RuntimeFactory(activityHandlers: [new RecordingOrderActivities()]))->create();

        self::assertSame(
            ['test.order.charge', 'test.order.reserve', 'test.order.notify'],
            $runtime->declaredActivities(),
        );
    }

    /**
     * Without this refusal, the declaration declares nothing: any class at all would run,
     * whether it is in `di.xml` or not, and the omission would only show up in production.
     */
    public function testAnUndeclaredWorkflowFailsNamingTheTypeAndWhereTypesAreDeclared(): void
    {
        $runtime = (new RuntimeFactory(activityHandlers: [new RecordingOrderActivities()]))->create();

        $this->expectException(UndeclaredWorkflowException::class);
        $this->expectExceptionMessageMatches('/OrderWorkflow/');
        $this->expectExceptionMessageMatches('/di\.xml/');

        $runtime->run(OrderWorkflow::class, ['orderId' => 'ORD-4242']);
    }
}
