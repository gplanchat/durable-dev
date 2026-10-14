<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\DurableBundle;
use Gplanchat\Durable\Exception\ReservedStubMethodName;
use Gplanchat\Durable\Exception\WorkflowRepositoryWorkflowNotRegistered;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use unit\DurableBundle\Fixtures\WorkflowWithAReservedSignal;
use unit\DurableBundle\Fixtures\WorkflowWithAReservedSignalRepository;
use unit\DurableBundle\Fixtures\WorkflowWithEnvironment;
use unit\DurableBundle\Fixtures\WorkflowWithEnvironmentRepository;
use unit\DurableBundle\Fixtures\WorkflowWithoutDependencies;
use unit\DurableBundle\Fixtures\WorkflowWithoutDependenciesRepository;

/**
 * A repository class with `#[AsWorkflowRepository]` is a service with no hand-written tag, and the
 * container refuses it at compilation when its workflow is not registered.
 */
final class AsWorkflowRepositoryAutoconfigurationTest extends TestCase
{
    public function testARepositoryWithTheAttributeIsAServiceThatAutowires(): void
    {
        $container = $this->compileWith([WorkflowWithoutDependencies::class, WorkflowWithoutDependenciesRepository::class]);

        self::assertTrue($container->has(WorkflowWithoutDependenciesRepository::class));
    }

    public function testARepositoryWhoseWorkflowIsNotRegisteredFailsTheCompilation(): void
    {
        try {
            $this->compileWith([WorkflowWithEnvironmentRepository::class]);
            self::fail('The compilation accepted a repository whose workflow is not registered.');
        } catch (WorkflowRepositoryWorkflowNotRegistered $e) {
            self::assertSame(WorkflowWithEnvironmentRepository::class, $e->repositoryClass);
            self::assertSame(WorkflowWithEnvironment::class, $e->workflowClass);
        }
    }

    public function testARepositoryOfAWorkflowWithAReservedMethodNameFailsTheCompilation(): void
    {
        try {
            $this->compileWith([WorkflowWithAReservedSignal::class, WorkflowWithAReservedSignalRepository::class]);
            self::fail('The compilation accepted a signal named after a verb of the stub.');
        } catch (ReservedStubMethodName $e) {
            self::assertSame(WorkflowWithAReservedSignal::class, $e->workflowClass);
            self::assertSame('Cancel', $e->method);
        }
    }

    /**
     * @param list<class-string> $classes
     */
    private function compileWith(array $classes): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        (new DurableExtension())->load([[]], $container);
        // What FrameworkExtension provides and this synthetic container lacks.
        $container->register('messenger.default_bus', \stdClass::class)->setPublic(true);

        foreach ($classes as $class) {
            $container->register($class, $class)->setAutoconfigured(true)->setAutowired(true)->setPublic(true);
        }

        (new DurableBundle())->build($container);
        $container->compile();

        return $container;
    }
}
