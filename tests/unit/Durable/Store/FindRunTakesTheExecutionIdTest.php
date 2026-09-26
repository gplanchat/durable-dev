<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use PHPUnit\Framework\TestCase;

/**
 * `findRun(executionId: …)` works on every catalog. PHP lets an implementation rename a parameter,
 * and a caller that passes it by name then fails on that one backend (user decision, after #552).
 */
final class FindRunTakesTheExecutionIdTest extends TestCase
{
    public function testEveryCatalogNamesItsParameterExecutionId(): void
    {
        foreach ([WorkflowRunCatalogInterface::class, InMemoryWorkflowRunCatalog::class, DbalWorkflowRunCatalog::class, IlluminateWorkflowRunCatalog::class, TemporalWorkflowRunCatalog::class] as $catalog) {
            self::assertSame('executionId', (new \ReflectionMethod($catalog, 'findRun'))->getParameters()[0]->getName(), $catalog);
        }
    }
}
