<?php

declare(strict_types=1);

namespace App\Ai\Watch;

use App\Ai\Chat\ChatTranscript;
use App\Ai\Live\AgentSignals;
use App\Ai\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;

/**
 * L'aiguillage : d'un fait de l'application vers les agents qui l'attendaient.
 *
 * Le signal `alerte` était joignable depuis toujours — n'importe quel service peut l'envoyer. Ce
 * qui manquait, c'était de savoir **à qui**. Une veille dit ce qu'elle guette
 * ({@see WatchSubject}) ; il restait à retrouver, à partir d'un sujet, les exécutions qui dorment
 * dessus.
 *
 * **Aucun nouveau magasin.** L'index est reconstruit à la demande : le catalogue des exécutions
 * dit lesquelles tournent, la projection du journal dit ce que chacune guette. Le journal reste la
 * seule source de vérité, et il n'y a pas de table de veilles à garder cohérente — donc pas de
 * ligne orpheline le jour où une exécution meurt sans se désinscrire.
 *
 * ponytail: c'est un balayage. Une lecture de journal par exécution vivante, par événement. À
 * l'échelle d'une démo — quelques conversations — c'est gratuit et ça ne peut pas dériver. Le jour
 * où le nombre d'exécutions vivantes est le facteur qui coûte, l'index devient une table écrite par
 * une activité au moment de l'inscription, avec une clé d'idempotence dérivée de
 * `executionId` + `callId` ; le reste de ce fichier ne bouge pas.
 *
 * **Toutes les veilles du sujet sont réveillées**, dans toutes les exécutions : deux conversations
 * qui attendent la même livraison la reçoivent toutes les deux. C'est voulu — un fait est un fait,
 * il n'appartient à personne.
 */
final readonly class WatchRouter
{
    /**
     * Ce que {@see \Gplanchat\Bridge\Temporal\WorkflowClient::workflowId()} préfixe. Le catalogue
     * rend l'identifiant Temporal ; la projection veut celui de l'exécution. La correspondance est
     * une chaîne, et c'est la seule chose de ce fichier qui connaisse le pont.
     */
    private const TEMPORAL_PREFIX = 'durable-';

    public function __construct(
        private ChatTranscript $transcript,
        private AgentSignals $signals,
        private ?WorkflowRunCatalogInterface $catalog = null,
    ) {
    }

    /**
     * @return list<string> les exécutions réveillées, pour que l'appelant puisse le dire
     */
    public function dispatch(BusinessEvent $event, int $scanLimit = 100): array
    {
        $reveilles = [];

        foreach ($this->runningAgents($scanLimit) as $executionId) {
            foreach ($this->transcript->forExecution($executionId)->watches as $watch) {
                if ($watch->subject !== $event->subject) {
                    continue;
                }

                // Une veille dont l'échéance est passée mais dont le minuteur n'a pas encore tiré
                // est encore ici. La réveiller n'est pas un problème : `WatchDesk::raise()` est
                // premier arrivé premier servi, et l'expiration qui suit ne rouvre rien.
                $this->signals->send($executionId, 'alerte', [
                    'callId' => $watch->callId,
                    'observation' => $event->describe(),
                ]);
                $reveilles[] = $executionId;
            }
        }

        return $reveilles;
    }

    /**
     * @return list<string>
     */
    private function runningAgents(int $limit): array
    {
        if (null === $this->catalog) {
            return [];
        }

        $executions = [];
        foreach ($this->catalog->listRuns(WorkflowRunStatus::Running, limit: $limit)->runs as $run) {
            if (DurableAgentWorkflow::TYPE !== $run->workflowName) {
                continue;
            }

            $executionId = self::executionIdOf($run);
            if (null !== $executionId) {
                $executions[] = $executionId;
            }
        }

        return $executions;
    }

    private static function executionIdOf(WorkflowRunDescription $run): ?string
    {
        $workflowId = (string) $run->groupId;

        return str_starts_with($workflowId, self::TEMPORAL_PREFIX)
            ? substr($workflowId, \strlen(self::TEMPORAL_PREFIX))
            : null;
    }
}
