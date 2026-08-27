<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Runtime;

use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowRegistry;

/**
 * Assemble le moteur pour un processus Magento.
 *
 * Cinq objets, aucun framework : c'est exactement ce que `WorkflowTestEnvironment`
 * assemble pour un test, et c'est ce qui rend un hôte de palier 1 possible du tout.
 * Le composant ne demande ni conteneur ni bus ; ce qu'un hôte doit fournir, c'est
 * un endroit où poser ces cinq-là et de quoi les atteindre.
 *
 * Cette fabrique est délibérément un objet Magento ordinaire plutôt qu'un
 * `di.xml` qui câblerait les cinq : le runner prend deux scalaires en plus des
 * quatre dépendances, et les exprimer en `<argument>` les aurait éloignés de la
 * seule ligne qui explique ce qu'ils bornent.
 */
/*
 * Pas `final` : Magento engendre un `Interceptor` qui étend toute classe que son
 * conteneur instancie, pour porter les plugins. Une classe finale fait échouer la
 * compilation du conteneur — « cannot extend final class » — et le message ne dit
 * pas que c'est la faute du mot-clé. C'est la maison qui écrit `final` partout ;
 * ici l'hôte l'interdit, et le dire vaut mieux que de le laisser deviner.
 */
class InMemoryRuntimeFactory
{
    /**
     * @param int   $maxActivityRetries Plafond quand une activité n'en fixe pas. `0` ne plafonne
     *                                  rien — et une activité sans `RetryLimit` réessaie
     *                                  indéfiniment, ce qui est le défaut de Temporal.
     * @param float $budgetSeconds      Borne globale d'une exécution. Elle existe parce que le
     *                                  point précédent rend « ça ne finit jamais » atteignable
     *                                  sans erreur.
     */
    public function __construct(
        private readonly int $maxActivityRetries = 0,
        private readonly float $budgetSeconds = InMemoryWorkflowRunner::DEFAULT_BUDGET_SECONDS,
    ) {
    }

    public function create(): MagentoRuntime
    {
        $eventStore = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $activities = new RegistryActivityExecutor();
        $workflows = new WorkflowRegistry();

        return new MagentoRuntime(
            $eventStore,
            $activities,
            $workflows,
            new InMemoryWorkflowRunner(
                $eventStore,
                $transport,
                $activities,
                $this->maxActivityRetries,
                $workflows,
                $this->budgetSeconds,
            ),
        );
    }
}
