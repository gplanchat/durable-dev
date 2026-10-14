<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Exception\WorkflowRepositoryWorkflowNotRegistered;
use Gplanchat\Durable\Workflow\ReservedStubMethods;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Refuses, at compilation, a repository declared with `#[AsWorkflowRepository]` whose workflow is
 * not among the classes tagged `durable.workflow`, and a workflow that declares a signal, query or
 * update method under a name the stub keeps for itself.
 */
final readonly class WorkflowRepositoryPass implements CompilerPassInterface
{
    public const TAG = 'durable.workflow_repository';

    public function process(ContainerBuilder $container): void
    {
        $registered = [];
        foreach (array_keys($container->findTaggedServiceIds('durable.workflow')) as $id) {
            $registered[ltrim($container->getDefinition($id)->getClass() ?? $id, '\\')] = true;
        }

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            /** @var class-string $repositoryClass */
            $repositoryClass = $container->getDefinition($id)->getClass() ?? $id;
            foreach ($tags as $tag) {
                /** @var class-string $workflow */
                $workflow = $tag['workflow'];
                if (!isset($registered[ltrim($workflow, '\\')])) {
                    throw new WorkflowRepositoryWorkflowNotRegistered($repositoryClass, $workflow);
                }
                ReservedStubMethods::assertFreeFor($workflow);
            }
        }
    }
}
