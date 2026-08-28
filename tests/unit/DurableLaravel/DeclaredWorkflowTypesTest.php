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
 * Ce qu'un hôte sans autoconfiguration doit rendre : la même classe, résolue par le même nom.
 */
final class DeclaredWorkflowTypesTest extends TestCase
{
    public function testAWorkflowWrittenForTheBundleResolvesHereUnmodified(): void
    {
        // GreetingWorkflow n'importe que `Gplanchat\Durable\` — aucun symbole de Laravel ni de
        // Symfony. C'est ce qui rend la phrase « sans modification » vérifiable plutôt que promise.
        $types = $this->declaring([GreetingWorkflow::class]);

        // Par le nom que l'attribut déclare…
        self::assertIsCallable($types->handlerFor('Greeting', []));
        // …et par le FQCN, parce qu'une reprise peut n'avoir que celui-là.
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

        // « Declared: none » plutôt qu'une liste vide : un message qui se termine sur deux
        // guillemets vides fait douter du message, pas de la configuration.
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
            'le registre est un singleton : deux workers du même processus partagent la même table',
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
