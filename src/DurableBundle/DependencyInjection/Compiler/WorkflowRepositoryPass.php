<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Attribute\RepositoryFor;
use Gplanchat\Durable\Exception\WorkflowRepositoryWorkflowNotRegistered;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Workflow\ReservedStubMethods;
use Gplanchat\Durable\WorkflowRepository;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The two forms of a repository, resolved at compilation. Both refuse a workflow that is not among
 * the classes tagged `durable.workflow`, and a workflow that declares a signal, query or update
 * method under a name the stub keeps for itself.
 *
 * - A class declared with `#[AsWorkflowRepository]` is a service of its own; autoconfiguration
 *   tags it.
 * - A constructor parameter typed {@see WorkflowRepository} and carrying `#[RepositoryFor]` of an
 *   autowired service receives a generic repository of that workflow, one per workflow.
 *
 * Runs before the autowiring pass: the argument is set by name, and autowiring leaves it alone.
 * Only public API of the container is used, so the pass behaves the same from Symfony 6.4 on.
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
            /** @var class-string $requestedBy */
            $requestedBy = $container->getDefinition($id)->getClass() ?? $id;
            foreach ($tags as $tag) {
                /** @var class-string $workflow */
                $workflow = $tag['workflow'];
                self::assertServed($registered, $requestedBy, $workflow);
            }
        }

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();
            if (!$definition->isAutowired() || $definition->isAbstract() || $definition->isSynthetic() || null === $class) {
                continue;
            }
            $constructor = $container->getReflectionClass($container->getParameterBag()->resolveValue($class), false)?->getConstructor();
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $attributes = $parameter->getAttributes(RepositoryFor::class);
                if ([] === $attributes || \array_key_exists('$' . $parameter->getName(), $definition->getArguments())) {
                    continue;
                }
                $workflow = $attributes[0]->newInstance()->workflow;
                self::assertServed($registered, $class, $workflow);

                $repositoryId = 'durable.workflow_repository.generic.' . ltrim($workflow, '\\');
                if (!$container->hasDefinition($repositoryId)) {
                    $container->setDefinition($repositoryId, new Definition(WorkflowRepository::class, [
                        new Reference(WorkflowRunCatalogInterface::class),
                        new Reference(WorkflowClientPort::class),
                        $workflow,
                    ]))->setPublic(false);
                }
                $definition->setArgument('$' . $parameter->getName(), new Reference($repositoryId));
            }
        }
    }

    /**
     * @param array<string, true> $registered
     * @param class-string        $requestedBy
     * @param class-string        $workflow
     */
    private static function assertServed(array $registered, string $requestedBy, string $workflow): void
    {
        if (!isset($registered[ltrim($workflow, '\\')])) {
            throw new WorkflowRepositoryWorkflowNotRegistered($requestedBy, $workflow);
        }
        ReservedStubMethods::assertFreeFor($workflow);
    }
}
