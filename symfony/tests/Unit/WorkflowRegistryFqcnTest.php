<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Samples\Workflow\SimpleActivity\SimpleActivityGreetingWorkflow;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

final class WorkflowRegistryFqcnTest extends TestCase
{
    public function testResolvesHandlerByAliasAndByFqcn(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerClass(SimpleActivityGreetingWorkflow::class);

        $alias = (new WorkflowDefinitionLoader())->workflowTypeForClass(SimpleActivityGreetingWorkflow::class);

        self::assertTrue($registry->has($alias));
        self::assertTrue($registry->has(SimpleActivityGreetingWorkflow::class));

        $payload = ['name' => 'World'];
        $handlerAlias = $registry->getHandler($alias, $payload);
        $handlerFqcn = $registry->getHandler(SimpleActivityGreetingWorkflow::class, $payload);
        self::assertIsCallable($handlerAlias);
        self::assertIsCallable($handlerFqcn);
    }
}
