<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Testing;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\Testing\ActivitySpy;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Le harnais doit savoir lancer un workflow **classe**, dans la forme de production.
 *
 * Aujourd'hui il ne prend qu'un `callable`, donc un workflow de test est une closure qui reçoit
 * l'environnement — une signature qu'aucun vrai workflow n'a depuis que l'environnement est passé
 * au constructeur. C'est la seule raison pour laquelle quarante-sept appels de la suite utilisent
 * encore `activity()` : dans une closure, il n'y a pas de constructeur où bâtir un stub.
 *
 * Tant que ces tests échouent, `activity()` ne peut pas quitter la surface publique — il n'y
 * aurait aucun remplacement à proposer aux tests.
 *
 * @see openspec/changes/workflow-authoring-surface — tâches 2.1 à 2.4
 */
final class WorkflowClassUnderTestTest extends TestCase
{
    public function testTheEnvironmentReachesTheConstructorAndTheInputReachesTheMethod(): void
    {
        $env = WorkflowTestEnvironment::inMemory([
            'greet' => static fn(array $p): string => 'Hello, ' . $p['name'] . '!',
        ]);

        $result = $env->runWorkflowClass(GreetingWorkflow::class, ['name' => 'Alice']);

        self::assertSame('Hello, Alice!', $result);
    }

    public function testTheDoubleReceivesTheArgumentsThePassedThroughTheStub(): void
    {
        $spy = ActivitySpy::returns('Hello, Bob!');
        $env = WorkflowTestEnvironment::inMemory(['greet' => $spy]);

        $env->runWorkflowClass(GreetingWorkflow::class, ['name' => 'Bob']);

        $spy->assertCalledTimes(1);
        // Le stub reconstruit la charge depuis les paramètres nommés du contrat : c'est ce qui
        // rend la faute de frappe impossible, et c'est ce que le test doit constater.
        $spy->assertCalledWith(['name' => 'Bob']);
    }

    public function testAFailingActivitySurfacesAsTheWorkflowFailure(): void
    {
        $env = WorkflowTestEnvironment::inMemory([
            'greet' => static function (array $p): never {
                throw new \DomainException('greeting refused');
            },
        ]);

        // Une activité qui échoue sans que le workflow l'attrape est rapportée comme une faute
        // d'algorithme, et le message nomme la cause — c'est ce qui rend le test lisible quand il
        // casse, et ça vaut d'être épinglé.
        $this->expectException(DurableWorkflowAlgorithmFailureException::class);
        $this->expectExceptionMessage('AsWorkflow did not handle activity failure');
        $this->expectExceptionMessage('DomainException: greeting refused');

        $env->runWorkflowClass(GreetingWorkflow::class, ['name' => 'Carol']);
    }

    public function testAWorkflowThatCallsNoActivityNeedsNothingConfigured(): void
    {
        // Aucun handler d'activité, donc aucun résolveur de contrat à configurer : un workflow
        // qui ne planifie rien doit pouvoir tourner sur un harnais nu.
        $result = WorkflowTestEnvironment::inMemory()->runWorkflowClass(
            EchoWorkflow::class,
            ['text' => 'quiet'],
        );

        self::assertSame('quiet', $result);
    }

    public function testTheClosureFormStillRuns(): void
    {
        $env = WorkflowTestEnvironment::inMemory([
            'greet' => static fn(array $p): string => 'Hello, ' . $p['name'] . '!',
        ]);

        // La forme anonyme reste : un test qui veut trois lignes ne doit pas déclarer une classe
        // et un contrat pour les écrire. Ce qui change, c'est qu'elle est la forme du harnais et
        // non celle d'un workflow.
        $result = $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await(
            $wf->activityStub(GreetingActivities::class)->greet('Dave'),
        ));

        self::assertSame('Hello, Dave!', $result);
    }
}

interface GreetingActivities
{
    #[AsActivityMethod('greet')]
    public function greet(string $name): string;
}

#[AsWorkflow(name: 'greeting')]
final class GreetingWorkflow
{
    private ActivityStub $greetings;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        // Une tentative : par défaut les activités retentent indéfiniment, et un échec ne
        // remonterait jamais — le workflow resterait bloqué jusqu'à épuisement du budget.
        $this->greetings = $environment->activityStub(
            GreetingActivities::class,
            ActivityOptions::of(retryLimit: 1),
        );
    }

    #[AsWorkflowMethod]
    public function run(string $name): string
    {
        /** @var Awaitable<string> $call */
        $call = $this->greetings->greet($name);

        return $this->environment->await($call);
    }
}

#[AsWorkflow(name: 'echo')]
final class EchoWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(string $text): string
    {
        return $text;
    }
}
