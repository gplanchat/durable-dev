<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Samples\Workflow\SimpleActivity\SimpleActivityGreetingWorkflow;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use PHPUnit\Framework\TestCase;

final class EventDataMapperExecutionStartedTest extends TestCase
{
    public function testExecutionStartedRoundTripIncludesWorkflowType(): void
    {
        $expected = (new WorkflowDefinitionLoader())->workflowTypeForClass(SimpleActivityGreetingWorkflow::class);
        $e = new ExecutionStarted('exec-1', ['workflowType' => $expected]);
        $row = EventDataMapper::fromDomainEvent($e);
        $restored = EventDataMapper::toDomainEvent($row);
        self::assertInstanceOf(ExecutionStarted::class, $restored);
        self::assertSame($expected, $restored->payload()['workflowType'] ?? null);
    }
}
