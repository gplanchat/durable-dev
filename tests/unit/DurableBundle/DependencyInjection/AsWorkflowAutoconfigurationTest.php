<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\DurableBundle;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use unit\DurableBundle\Fixtures\NotAWorkflow;
use unit\DurableBundle\Fixtures\WorkflowWithAnUnresolvableStub;
use unit\DurableBundle\Fixtures\WorkflowWithArguments;
use unit\DurableBundle\Fixtures\WorkflowWithEnvironment;
use unit\DurableBundle\Fixtures\WorkflowWithoutDependencies;

/**
 * Three attributes out of four autoconfigure themselves. The fourth — the one that declares a
 * workflow — required a hand-written tag, per directory, in the application's `services.yaml`.
 *
 * Issue #255 announces a trap: a workflow is instantiated by reflection by `WorkflowDefinitionLoader`,
 * never by the container, and its constructor receives a `WorkflowEnvironment` that is not a
 * service. Tagging alone would therefore, it says, fail compilation on a class the container will
 * never build.
 *
 * These cases test it instead of assuming it: they really compile the container.
 */
final class AsWorkflowAutoconfigurationTest extends TestCase
{
    public function testAWorkflowWithTheAttributeIsRegisteredWithoutAHandWrittenTag(): void
    {
        $container = $this->compileWith([WorkflowWithoutDependencies::class]);

        self::assertContains(
            WorkflowWithoutDependencies::class,
            $this->registeredClasses($container),
            'the attribute must be enough, as it already is for the other three',
        );
    }

    /**
     * The heart of the matter. If the trap of #255 bit, this call would throw at compile time.
     */
    public function testAWorkflowReceivingTheEnvironmentStillCompiles(): void
    {
        $container = $this->compileWith([WorkflowWithEnvironment::class]);

        self::assertContains(WorkflowWithEnvironment::class, $this->registeredClasses($container));
    }

    public function testAWorkflowTakingItsStubsAsArgumentsCompiles(): void
    {
        $container = $this->compileWith([WorkflowWithArguments::class]);

        self::assertContains(WorkflowWithArguments::class, $this->registeredClasses($container));
    }

    /**
     * The registry loads its classes when it is built, at run time. Validating in the pass moves a
     * stub the loader cannot build to the container compilation, where a deploy still stops.
     */
    public function testAWorkflowWithAnUnresolvableStubFailsTheCompilation(): void
    {
        $this->expectExceptionMessage('WorkflowWithAnUnresolvableStub::run() parameter $greeting is an ActivityStub without #[Activities(Contract::class)]');

        $this->compileWith([WorkflowWithAnUnresolvableStub::class]);
    }

    public function testAClassWithoutTheAttributeDoesNotReachTheRegistry(): void
    {
        $container = $this->compileWith([NotAWorkflow::class]);

        self::assertNotContains(NotAWorkflow::class, $this->registeredClasses($container));
    }

    /**
     * @param list<class-string> $classes
     */
    private function compileWith(array $classes): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        (new DurableExtension())->load([[]], $container);

        // What FrameworkExtension provides and this synthetic container lacks: the default bus,
        // referenced by the resume dispatcher.
        $container->register('messenger.default_bus', \stdClass::class)->setPublic(true);

        foreach ($classes as $class) {
            $container->register($class, $class)
                ->setAutoconfigured(true)
                ->setAutowired(true)
                ->setPublic(false)
            ;
        }

        (new DurableBundle())->build($container);
        $container->compile();

        return $container;
    }

    /**
     * @return list<string>
     */
    private function registeredClasses(ContainerBuilder $container): array
    {
        $registered = [];
        foreach ($container->getDefinition(WorkflowRegistry::class)->getMethodCalls() as [$method, $arguments]) {
            if ('registerClass' === $method) {
                $registered[] = (string) $arguments[0];
            }
        }

        return $registered;
    }
}
