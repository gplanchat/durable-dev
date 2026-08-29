<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Block\Adminhtml;

use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * L'état du backend, ce que l'écran compte, et jusqu'où il lit — au-dessus de la grille.
 *
 * ⚠ **Une grille vide ne dit rien toute seule.** Elle se lit pareil quand rien n'a tourné, quand la
 * grappe est tombée, et quand le journal ne survit pas à la requête. Cet écran ne sondait pas : une
 * grappe morte y rendait une grille vide et sereine — la pire des deux erreurs possibles, puisque
 * l'exploitant en conclut qu'il n'y a rien à voir. Trois états, donc, et le port les distingue déjà.
 *
 * Les compteurs portent sur **la fenêtre que cet écran lit**, pas sur l'historique de la boutique,
 * et la bannière le dit. Un intitulé « total » sous lequel on lit vingt apprend à l'exploitant
 * qu'une application qui a enregistré cinq cents exécutions en a vingt.
 *
 * ponytail: la fenêtre est lue une seconde fois ici, la grille ayant déjà lu la sienne. Deux appels
 * sur un écran d'administration, contre un fournisseur de données qui devrait rendre des compteurs
 * que le châssis de la grille ne sait pas afficher. Si ça pèse, la sortie est un cache de requête
 * autour du catalogue, pas un couplage entre la bannière et la grille.
 */
/*
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui l'étend.
 */
class ProcessHistory extends Template
{
    private ?BackendHealth $health = null;

    /** @var array<string, int>|null */
    private ?array $counters = null;

    public function __construct(
        Context $context,
        private readonly RuntimeFactory $runtimeFactory,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getHealth(): BackendHealth
    {
        return $this->health ??= $this->runtimeFactory->catalog()->checkHealth();
    }

    /**
     * Le journal de cet hôte vit-il dans ce processus ? Alors il est né avec cette requête et
     * mourra avec elle, et la grille sera vide — ce qui est la bonne réponse.
     *
     * Le fait vient désormais du port et non de `hasCluster()` : c'est le catalogue in-memory qui
     * sait qu'il est éphémère, pas l'hôte qui le devine à l'absence d'un DSN.
     */
    public function isEphemeral(): bool
    {
        return $this->getHealth()->ephemeral;
    }

    public function isReachable(): bool
    {
        return $this->getHealth()->reachable;
    }

    /**
     * Un compteur par issue, sur la fenêtre que cet écran lit.
     *
     * @return array<string, int>
     */
    public function getCounters(): array
    {
        if ($this->counters === null) {
            $this->counters = RunDashboard::outcomeCounters(
                $this->runtimeFactory->catalog()->listRuns(limit: RuntimeFactory::OBSERVATION_WINDOW)->runs,
            );
        }

        return $this->counters;
    }

    public function getWindow(): int
    {
        return RuntimeFactory::OBSERVATION_WINDOW;
    }
}
