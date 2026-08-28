<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use App\Durable\Activity\TickActivityInterface;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow('TimerThenTickWorkflow')]
final class TimerThenTickWorkflow
{
    private readonly ActivityStub $tick;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->tick = $environment->activityStub(TickActivityInterface::class);
    }

    #[AsWorkflowMethod]
    public function run(float $seconds = 0.01): string
    {
        // `sleep()` et non `timer()` : le second rend un awaitable. L'appeler sans l'attendre
        // démarre un minuteur que personne ne regarde, et le workflow enchaîne — l'historique
        // porte alors un `TimerStarted` sans `TimerFired`. Le nom de ce workflow promet l'inverse.
        $this->environment->sleep($seconds);

        return $this->environment->await($this->tick->tick());
    }
}
