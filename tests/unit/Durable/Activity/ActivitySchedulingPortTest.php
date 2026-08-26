<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Activity;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\ActivityMethod;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Testing\ActivitySpy;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * `activity()` est la primitive sous `activityStub()`, pas à côté : le stub planifiait en
 * l'appelant. Elle doit quitter la surface qu'un auteur de workflow atteint, sans que le stub
 * cesse de fonctionner et sans que le journal bouge.
 *
 * Le troisième point est celui qui compte. Une exécution enregistrée avant ce changement doit
 * rejouer à l'identique : si la forme de fil bouge, la rupture n'est plus une rupture d'API, c'est
 * une rupture de données.
 *
 * @see openspec/changes/workflow-authoring-surface — tâches 4.1 à 4.3
 */
final class ActivitySchedulingPortTest extends TestCase
{
    public function testTheEnvironmentExposesNoSchedulingVerb(): void
    {
        $reflection = new \ReflectionClass(WorkflowEnvironment::class);

        $public = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $public[] = $method->getName();
        }

        // Nommer l'activité par une chaîne et lui passer un tableau libre est la forme que la
        // bibliothèque n'enseigne plus : une faute de frappe y produit une activité qui n'est
        // jamais planifiée, au lieu d'une erreur de type.

        self::assertNotContains('activity', $public);
    }

    public function testTheEnvironmentExposesNoQueryPlumbing(): void
    {
        $reflection = new \ReflectionClass(WorkflowEnvironment::class);

        $public = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $public[] = $method->getName();
        }

        // Un auteur déclare `#[QueryMethod]` et le moteur câble. Ces trois-là étaient sur
        // l'environnement parce que c'est l'objet que le moteur avait sous la main, pas parce
        // qu'un workflow en a besoin — les atteindre revenait à court-circuiter la déclaration.
        self::assertNotContains('registerQueryHandler', $public);
        self::assertNotContains('callQueryHandler', $public);
        self::assertNotContains('hasQueryHandler', $public);
    }

    public function testTheStubStillSchedulesThroughTheNarrowPort(): void
    {
        $spy = ActivitySpy::returns('charged');
        $env = WorkflowTestEnvironment::inMemory(['charge' => $spy]);

        $result = $env->runWorkflowClass(PortWorkflow::class, ['orderId' => 'ORD-7']);

        self::assertSame('charged', $result);
        $spy->assertCalledOnce();
        $spy->assertCalledWith(['orderId' => 'ORD-7']);
    }

    public function testTheStubCarriesItsOptionsToEveryCall(): void
    {
        $spy = ActivitySpy::returns('charged');
        $env = WorkflowTestEnvironment::inMemory(['charge' => $spy]);

        $env->runWorkflowClass(TwiceCallingWorkflow::class, ['orderId' => 'ORD-8'], 'exec-options');

        $scheduled = [];
        foreach ($env->getEventStore()->readStream('exec-options') as $event) {
            if ($event instanceof ActivityScheduled) {
                $scheduled[] = $event;
            }
        }

        self::assertCount(2, $scheduled, 'les deux appels du stub doivent être planifiés');
        foreach ($scheduled as $event) {
            self::assertSame('charge', $event->activityName());
        }
    }

    public function testTheJournalDoesNotMove(): void
    {
        $env = WorkflowTestEnvironment::inMemory([
            'charge' => static fn (array $p): string => 'charged:'.$p['orderId'],
        ]);

        $env->runWorkflowClass(PortWorkflow::class, ['orderId' => 'ORD-9'], 'exec-journal');

        $recorded = [];
        foreach ($env->getEventStore()->readStream('exec-journal') as $event) {
            $recorded[] = (new \ReflectionClass($event))->getShortName();
        }

        // Épinglé, et volontairement en dur : ce test existe pour interdire un changement, pas
        // pour décrire un comportement. S'il casse, c'est le rejeu des exécutions déjà
        // enregistrées qui est en jeu.
        self::assertSame([
            'ExecutionStarted',
            'ActivityScheduled',
            'ActivityTaskStarted',
            'ActivityTaskCompleted',
            'ActivityCompleted',
            'ExecutionCompleted',
        ], $recorded);
    }
}

interface PortActivities
{
    #[ActivityMethod('charge')]
    public function charge(string $orderId): string;
}

#[Workflow(name: 'port')]
final class PortWorkflow
{
    private ActivityStub $orders;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->orders = $environment->activityStub(PortActivities::class);
    }

    #[WorkflowMethod]
    public function run(string $orderId): string
    {
        return $this->environment->await($this->orders->charge($orderId));
    }
}

#[Workflow(name: 'port-twice')]
final class TwiceCallingWorkflow
{
    private ActivityStub $orders;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->orders = $environment->activityStub(
            PortActivities::class,
            ActivityOptions::of(retryLimit: 1),
        );
    }

    #[WorkflowMethod]
    public function run(string $orderId): string
    {
        $this->environment->await($this->orders->charge($orderId));

        return $this->environment->await($this->orders->charge($orderId));
    }
}
