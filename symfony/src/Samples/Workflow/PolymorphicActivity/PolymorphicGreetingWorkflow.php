<?php

declare(strict_types=1);

namespace App\Samples\Workflow\PolymorphicActivity;

use App\Samples\Activity\ByeActivityInterface;
use App\Samples\Activity\HelloActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Port de samples-php PolymorphicActivity : deux contrats d’activité distincts (préfixes de noms d’activité).
 *
 * @return list<string>
 */
#[Workflow('Samples_Polymorphic_Greeting')]
final class PolymorphicGreetingWorkflow
{
    private readonly ActivityStub $hello;

    private readonly ActivityStub $bye;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->hello = $environment->activityStub(
            HelloActivityInterface::class,
        );
        $this->bye = $environment->activityStub(
            ByeActivityInterface::class,
        );
    }

    #[WorkflowMethod]
    public function run(string $name = 'World'): array
    {
        return [
            $this->environment->await($this->hello->hello($name)),
            $this->environment->await($this->bye->bye($name)),
        ];
    }
}
