<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use PHPUnit\Framework\TestCase;
use unit\DurableModule\Fixture\InjectedOrderWorkflow;
use unit\DurableModule\Fixture\RecordingOrderActivities;

/**
 * The documentation injects activity stubs as `#[Activities]` arguments of the workflow method,
 * on every host. Magento declares its workflows in di.xml rather than autoconfiguring them: this
 * is the runtime that declaration builds, running a workflow written that way.
 */
final class AnActivitiesArgumentIsInjectedTest extends TestCase
{
    public function testTheInjectedStubRunsTheActivities(): void
    {
        $runtime = (new RuntimeFactory(
            workflowClasses: [InjectedOrderWorkflow::class],
            activityHandlers: [new RecordingOrderActivities()],
        ))->create();

        self::assertSame('notify:charge:ORD-4242', $runtime->run(InjectedOrderWorkflow::class, ['orderId' => 'ORD-4242']));
    }
}
