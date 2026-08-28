<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\Workflow\ChildWorkflowStub;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Un stub assemble, il n'attend pas.
 *
 * `ChildWorkflowStub::__call()` rendait le résultat de l'enfant, déjà attendu, là où
 * `ActivityStub` rend un `Awaitable`. L'asymétrie ne se voit qu'au moment de composer, et alors la
 * forme typée ne sait pas exprimer ce qu'on veut : c'est pour ça que l'application d'exemple
 * lançait ses deux enfants parallèles en les nommant par une chaîne.
 *
 * DUR033 l'avait déjà tranché — « await() est la seule méthode qui attend » — mais il énumérait
 * les méthodes de l'environnement, pas celles du stub.
 *
 * @see openspec/changes/child-workflow-surface
 */
final class ChildWorkflowSurfaceTest extends TestCase
{
    public function testTheEnvironmentExposesNoChildWorkflowVerb(): void
    {
        $reflection = new \ReflectionClass(WorkflowEnvironment::class);

        $public = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $public[] = $method->getName();
        }

        self::assertNotContains('scheduleChildWorkflow', $public);
        // Celle-ci attendait pour l'appelant, ce qui la rendait incomposable par construction.
        self::assertNotContains('executeChildWorkflow', $public);
    }

    public function testAStubCallReturnsAnAwaitableRatherThanTheResult(): void
    {
        $env = WorkflowTestEnvironment::inMemory();
        $env->registerWorkflowClass(EchoChild::class);

        $result = $env->runWorkflowClass(AwaitingParent::class, ['text' => 'hello']);

        self::assertSame('child:hello', $result);
    }

    public function testTwoChildrenCanBeRaced(): void
    {
        $env = WorkflowTestEnvironment::inMemory();
        $env->registerWorkflowClass(EchoChild::class);

        // Le cas qui motive tout le change : impossible à écrire tant que le stub attendait, parce
        // que le premier enfant se serait réglé avant que le second ne démarre.
        $result = $env->runWorkflowClass(RacingParent::class, ['first' => 'a', 'second' => 'b']);

        self::assertContains($result, ['child:a', 'child:b']);
    }

    public function testCallingSomethingOtherThanTheEntryMethodFails(): void
    {
        $env = WorkflowTestEnvironment::inMemory();
        $env->registerWorkflowClass(EchoChild::class);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('is not the workflow entry point');

        $env->runWorkflowClass(WrongMethodParent::class, []);
    }
}

#[AsWorkflow(name: 'echo-child')]
final class EchoChild
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(string $text): string
    {
        return 'child:' . $text;
    }
}

#[AsWorkflow(name: 'awaiting-parent')]
final class AwaitingParent
{
    private ChildWorkflowStub $child;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->child = $environment->childWorkflowStub(EchoChild::class);
    }

    #[AsWorkflowMethod]
    public function run(string $text): string
    {
        /** @var Awaitable<string> $call */
        $call = $this->child->run($text);

        return $this->environment->await($call);
    }
}

#[AsWorkflow(name: 'racing-parent')]
final class RacingParent
{
    private ChildWorkflowStub $child;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->child = $environment->childWorkflowStub(EchoChild::class);
    }

    #[AsWorkflowMethod]
    public function run(string $first, string $second): string
    {
        return $this->environment->await($this->environment->any(
            $this->child->run($first),
            $this->child->run($second),
        ));
    }
}

#[AsWorkflow(name: 'wrong-method-parent')]
final class WrongMethodParent
{
    private ChildWorkflowStub $child;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->child = $environment->childWorkflowStub(EchoChild::class);
    }

    #[AsWorkflowMethod]
    public function run(): mixed
    {
        return $this->environment->await($this->child->notTheEntryPoint('x'));
    }
}
