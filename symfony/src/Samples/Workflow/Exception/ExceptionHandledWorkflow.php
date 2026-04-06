<?php

declare(strict_types=1);

namespace App\Samples\Workflow\Exception;

use App\Samples\Activity\FlakyActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Inspiré de samples-php Exception : l’échec d’activité est intercepté dans le workflow.
 */
#[Workflow('Samples_Exception_Handled')]
final class ExceptionHandledWorkflow
{
    private readonly ActivityStub $flaky;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->flaky = $environment->activityStub(
            FlakyActivityInterface::class,
            ActivityOptions::default()
                ->withMaxAttempts(1)
        );
    }

    #[WorkflowMethod]
    public function run(bool $shouldFail = true): string
    {
        try {
            return $this->environment->await($this->flaky->maybeFail($shouldFail));
        } catch (DurableActivityFailedException $e) {
            return 'Caught: '.$e->getMessage();
        }
    }
}
