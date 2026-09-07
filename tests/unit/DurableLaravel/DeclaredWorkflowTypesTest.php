<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Laravel;

use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Laravel\Workflow\DeclaredWorkflowTypes;
use Gplanchat\Durable\WorkflowRegistry;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use unit\DurableLaravel\Fixtures\GreetingWorkflow;

/**
 * What a host without autoconfiguration has to deliver: the same class, resolved by the same name.
 */
final class DeclaredWorkflowTypesTest extends TestCase
{
    public function testAWorkflowWrittenForTheBundleResolvesHereUnmodified(): void
    {
        // GreetingWorkflow imports nothing but `Gplanchat\Durable\` — no Laravel symbol and no
        // Symfony one. That is what makes the phrase "unmodified" checkable rather than promised.
        $types = $this->declaring([GreetingWorkflow::class]);

        // By the name the attribute declares…
        self::assertIsCallable($types->handlerFor('Greeting', []));
        // …and by the FQCN, because a resume may have nothing but that one.
        self::assertIsCallable($types->handlerFor(GreetingWorkflow::class, []));
    }

    public function testAnUndeclaredTypeFailsNamingItselfAndWhereTypesAreDeclared(): void
    {
        $types = $this->declaring([GreetingWorkflow::class]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no workflow declared for type "Invoicing"');
        $this->expectExceptionMessage('"workflows" key of config/durable.php');
        $this->expectExceptionMessage(GreetingWorkflow::class);

        $types->handlerFor('Invoicing', []);
    }

    public function testAnApplicationThatDeclaresNothingSaysSo(): void
    {
        $types = $this->declaring([]);

        // "Declared: none" rather than an empty list: a message that ends on two empty quotes
        // casts doubt on the message, not on the configuration.
        $this->expectExceptionMessage('Declared: none.');

        $types->handlerFor('Greeting', []);
    }

    public function testTheRegistryComesFromTheContainerAlreadyPopulated(): void
    {
        $app = $this->container([GreetingWorkflow::class]);
        (new DurableServiceProvider($app))->register();

        self::assertTrue($app->make(WorkflowRegistry::class)->has('Greeting'));
        self::assertSame(
            $app->make(DeclaredWorkflowTypes::class),
            $app->make(DeclaredWorkflowTypes::class),
            'the registry is a singleton: two workers in the same process share the same table',
        );
    }

    /** @param list<class-string> $declared */
    private function declaring(array $declared): DeclaredWorkflowTypes
    {
        $app = $this->container($declared);
        (new DurableServiceProvider($app))->register();

        return $app->make(DeclaredWorkflowTypes::class);
    }

    /** @param list<class-string> $declared */
    private function container(array $declared): Container
    {
        $app = new Container();
        $app->instance('config', new \ArrayObject(
            ['durable' => ['backend' => 'memory', 'workflows' => $declared]],
            \ArrayObject::ARRAY_AS_PROPS,
        ));

        return $app;
    }
}
