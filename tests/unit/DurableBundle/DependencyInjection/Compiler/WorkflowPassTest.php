<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Bundle\Command\DebugDurableCommand;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\WorkflowPass;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use unit\Durable\Fixtures\ShippingWorkflow;

final class WorkflowPassTest extends TestCase
{
    public function testTheDebugCommandReceivesTheRegisteredWorkflowClasses(): void
    {
        $container = new ContainerBuilder();
        $container->register(WorkflowRegistry::class);
        $container->register('durable.command.debug', DebugDurableCommand::class)->setArguments([[]]);
        $container->register(ShippingWorkflow::class)->addTag('durable.workflow');

        (new WorkflowPass())->process($container);

        self::assertSame([ShippingWorkflow::class], $container->getDefinition('durable.command.debug')->getArgument(0));
    }
}
