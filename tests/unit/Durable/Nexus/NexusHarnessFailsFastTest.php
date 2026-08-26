<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Un workflow qui appelle une opération Nexus sous le harnais en mémoire doit **échouer vite**.
 *
 * Le test du tampon (§3.4) prouve que la commande est refusée. Il ne prouve pas ce qui compte pour
 * qui écrit un workflow : que le refus **traverse** le moteur au lieu d'être avalé quelque part et
 * de laisser l'exécution suspendue. C'est la différence entre un développeur qui lit une erreur en
 * trois secondes et un développeur qui regarde un test qui ne finit pas.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §5.1 §5.2
 */
final class NexusHarnessFailsFastTest extends TestCase
{
    public function testCallingNexusUnderTheInMemoryHarnessFailsInsteadOfHanging(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);

        $this->expectException(NexusUnsupportedByBackendException::class);

        $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await(
            $wf->nexusOperation('billing-endpoint', 'billing', 'charge', ['amount' => 10]),
        ));
    }

    public function testTheFailureNamesTheBackendAndTheWayOut(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);

        try {
            $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await(
                $wf->nexusOperation('billing-endpoint', 'billing', 'charge'),
            ));
            self::fail('Le harnais en mémoire a accepté une opération Nexus.');
        } catch (NexusUnsupportedByBackendException $e) {
            self::assertStringContainsString('journal', $e->getMessage());
            self::assertStringContainsString('Temporal', $e->getMessage());
        }
    }

    public function testTheWorkflowFailsBeforeAnythingIsAwaited(): void
    {
        // Le refus tombe à la planification, pas à l'attente : un `await()` jamais atteint est ce
        // qui distingue « échoue vite » de « échoue après un délai ».
        $env = WorkflowTestEnvironment::inMemory([]);
        $reached = false;

        try {
            $env->run(static function (WorkflowEnvironment $wf) use (&$reached): mixed {
                $awaitable = $wf->nexusOperation('billing-endpoint', 'billing', 'charge');
                $reached = true;

                return $wf->await($awaitable);
            });
        } catch (NexusUnsupportedByBackendException) {
            // attendu
        }

        self::assertFalse($reached, 'L’appel a rendu un awaitable au lieu de refuser sur-le-champ.');
    }
}
