<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Un workflow ordinaire, et c'est tout l'intérêt : il n'importe rien de Laravel ni de Symfony.
 *
 * Les seuls symboles qu'il connaît viennent de `Gplanchat\Durable\`, donc la même classe se déclare
 * au bundle Symfony et à ce paquet sans une ligne de différence. Ce que l'hôte change, c'est où le
 * journal atterrit et qui draine la file, jamais la classe.
 */
#[AsWorkflow('Greeting')]
final class GreetingWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(string $who = 'world'): string
    {
        return 'hello ' . $who;
    }
}
