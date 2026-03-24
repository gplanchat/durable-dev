<?php

declare(strict_types=1);

namespace App\Durable;

use Gplanchat\Durable\ActivityExecutor;

/**
 * Enregistre activités et workflows inspirés des samples Temporal PHP (SimpleActivity,
 * AsyncActivity / promesses parallèles, Child, timers, side-effect).
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
    ) {
    }

    public function register(): void
    {
        $this->registerActivities();
    }

    private function registerActivities(): void
    {
        $this->activityExecutor->register('composeGreeting', static function (array $payload): string {
            $name = (string) ($payload['name'] ?? 'World');

            return \sprintf('Hello, %s!', $name);
        });

        $this->activityExecutor->register('echoUpper', static function (array $payload): string {
            return strtoupper((string) ($payload['text'] ?? ''));
        });

        $this->activityExecutor->register('tick', static fn (): string => 'tick');
    }
}
