<?php

declare(strict_types=1);

namespace App\Durable;

use App\Durable\Activity\EchoActivityHandler;
use App\Durable\Activity\GreetingActivityHandler;
use App\Durable\Activity\TickActivityHandler;
use Gplanchat\Durable\ActivityExecutor;

/**
 * Relie les noms d'activité (attribut ActivityMethod sur l'interface) aux classes métier qui les implémentent.
 * Les workflows ne voient que les interfaces ; le worker exécute ces handlers.
 *
 * Les workflows sont enregistrés via le tag durable.workflow (voir services.yaml).
 *
 * @see https://github.com/temporalio/samples-php
 */
final class DurableSamplesConfigurator
{
    public const WORKFLOW_GREETING = 'GreetingWorkflow';

    public const WORKFLOW_PARALLEL_GREETING = 'ParallelGreetingWorkflow';

    public const WORKFLOW_ECHO_CHILD = 'EchoChildWorkflow';

    public const WORKFLOW_PARENT_CALLS_CHILD = 'ParentCallsEchoChildWorkflow';

    public const WORKFLOW_TIMER_THEN_TICK = 'TimerThenTickWorkflow';

    public const WORKFLOW_SIDE_EFFECT_ID = 'SideEffectRandomIdWorkflow';

    public function __construct(
        private readonly ActivityExecutor $activityExecutor,
        private readonly GreetingActivityHandler $greetingActivityHandler,
        private readonly EchoActivityHandler $echoActivityHandler,
        private readonly TickActivityHandler $tickActivityHandler,
    ) {
    }

    public function register(): void
    {
        $this->registerActivities();
    }

    private function registerActivities(): void
    {
        $this->activityExecutor->register(
            'composeGreeting',
            $this->greetingActivityHandler->composeGreetingFromPayload(...),
        );
        $this->activityExecutor->register(
            'echoUpper',
            $this->echoActivityHandler->echoUpperFromPayload(...),
        );
        $this->activityExecutor->register(
            'tick',
            $this->tickActivityHandler->tickFromPayload(...),
        );
    }
}
