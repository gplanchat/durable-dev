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

    public const TIMER_THEN_TICK = 'TimerThenTickWorkflow';

    public const SIDE_EFFECT_ID = 'SideEffectRandomIdWorkflow';
}
