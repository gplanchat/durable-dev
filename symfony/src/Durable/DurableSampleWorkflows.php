<?php

declare(strict_types=1);

namespace App\Durable;

/**
 * Noms des workflows exemples (types enregistrés dans {@see \Gplanchat\Durable\WorkflowRegistry}).
 */
final class DurableSampleWorkflows
{
    public const GREETING = 'GreetingWorkflow';

    public const PARALLEL_GREETING = 'ParallelGreetingWorkflow';

    public const ECHO_CHILD = 'EchoChildWorkflow';

    public const PARENT_CALLS_CHILD = 'ParentCallsEchoChildWorkflow';

    /** Deux {@link EchoChildWorkflow} planifiés en parallèle via {@see WorkflowEnvironment::all}. */
    public const PARALLEL_CHILD_ECHO = 'ParallelChildEchoWorkflow';

    public const TIMER_THEN_TICK = 'TimerThenTickWorkflow';

    public const SIDE_EFFECT_ID = 'SideEffectRandomIdWorkflow';
}
