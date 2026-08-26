<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Durable\Exception\DurableUpdateFailedException;

/**
 * Un update qui répond, contre un vrai serveur.
 *
 * C'est le chemin entier : le client envoie l'update, le serveur le remet au worker en message de
 * protocole *hors historique*, le handler produit l'issue, le worker accepte et répond sur la même
 * tâche, et l'appelant reçoit la valeur de retour. Aucune partie de cette chaîne ne se vérifie
 * contre un faux serveur — d'où ce test (tâches 5.5 et 7.3).
 */
final class WorkflowUpdateTest extends TemporalServerTestCase
{
    public function testAnUpdateHandlerAnswersItsCaller(): void
    {
        $executionId = $this->startWorkflow('Updatable', []);

        $answer = $this->workflowClient()->update($this->workflowId($executionId), 'approve', ['by' => 'alice']);

        self::assertSame(['ok' => true, 'by' => 'alice'], $answer);

        $result = $this->workflowClient()->pollForCompletion($executionId, 250, 160);
        self::assertSame(['ok' => true, 'by' => 'alice'], $result['approved'] ?? null);
    }

    public function testAFailingUpdateDoesNotFailTheWorkflow(): void
    {
        $executionId = $this->startWorkflow('Updatable', []);
        $workflowId = $this->workflowId($executionId);

        try {
            $this->workflowClient()->update($workflowId, 'refuse', ['by' => 'bob']);
            self::fail('l’update devait échouer');
        } catch (DurableUpdateFailedException $e) {
            self::assertStringContainsString('approbation refusée', $e->getMessage());
        }

        // L'exécution est intacte : elle répond encore, et va au bout.
        self::assertSame(['ok' => true, 'by' => 'alice'], $this->workflowClient()->update($workflowId, 'approve', ['by' => 'alice']));
        $result = $this->workflowClient()->pollForCompletion($executionId, 250, 160);
        self::assertSame(['ok' => true, 'by' => 'alice'], $result['approved'] ?? null);
    }
}
