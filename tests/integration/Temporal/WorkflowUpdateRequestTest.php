<?php

declare(strict_types=1);

namespace integration\Temporal;

/**
 * L'aller simple d'un update, contre un vrai serveur.
 *
 * Le worker ne traite pas encore les updates (tâche 5.5) : cette exécution ne peut donc pas
 * aboutir. Ce que le test prouve est en amont — que la requête est **bien formée**. Le serveur
 * refusait la nôtre d'emblée, parce que `Update.Request.meta` n'était jamais renseigné ; il ne
 * la refuse plus, et l'appel va désormais jusqu'à attendre un worker.
 */
final class WorkflowUpdateRequestTest extends TemporalServerTestCase
{
    public function testTheServerAcceptsTheUpdateRequestAsWellFormed(): void
    {
        $executionId = $this->startWorkflow('Sleeper', []);

        $rejection = null;

        try {
            $this->workflowClient()->update($this->workflowId($executionId), 'approve', ['by' => 'alice']);
        } catch (\Throwable $e) {
            $rejection = $e;
        }

        // Avant le correctif : refus immédiat, INVALID_ARGUMENT « Update meta is not set on
        // request » — la requête n'atteignait jamais l'exécution. Après : plus de refus de forme,
        // que l'appel rende la main sans résultat (aucun worker n'accepte l'update) ou qu'il
        // échoue pour une autre raison.
        if (null !== $rejection) {
            self::assertStringNotContainsStringIgnoringCase(
                'meta is not set',
                $rejection->getMessage(),
                "la requête d'update doit être bien formée : " . $rejection->getMessage(),
            );

            return;
        }

        self::assertNull($rejection);
    }
}
