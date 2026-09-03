<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * La forme que le guide de démarrage enseigne : un constructeur qui reçoit l'environnement, lequel
 * n'est **pas** un service du conteneur. C'est cette forme que l'issue #255 annonce comme piégeuse
 * dès qu'on autoconfigure l'attribut.
 */
#[AsWorkflow('AvecEnvironnement')]
final class WorkflowAvecEnvironnement
{
    public function __construct(private readonly WorkflowEnvironment $environment) {}

    #[AsWorkflowMethod]
    public function run(string $quoi): string
    {
        return $quoi;
    }
}
