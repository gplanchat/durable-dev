<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Samples\Workflow\SimpleActivity\SimpleActivityGreetingWorkflow;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use PHPUnit\Framework\TestCase;

final class WorkflowDefinitionLoaderAliasTest extends TestCase
{
    public function testAliasForTemporalInteropMapsFqcnToWorkflowAttributeName(): void
    {
        $loader = new WorkflowDefinitionLoader();
        $alias = $loader->workflowTypeForClass(SimpleActivityGreetingWorkflow::class);
        self::assertSame($alias, $loader->aliasForTemporalInterop(SimpleActivityGreetingWorkflow::class));
    }

    public function testAliasForTemporalInteropLeavesNonClassStringUnchanged(): void
    {
        $loader = new WorkflowDefinitionLoader();
        self::assertSame('IntegrationTest_Custom', $loader->aliasForTemporalInterop('IntegrationTest_Custom'));
    }
}
