<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Block\Adminhtml;

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Ce que la grille lit, et pourquoi elle peut être vide sans être en panne.
 *
 * Rien ici n'est propre à Magento sous la surface : `InMemoryWorkflowRunCatalog` lit **n'importe
 * quel** magasin d'événements et rend les mêmes `WorkflowRunDescription` que le tableau de bord
 * Sylius affiche. Une seule observation, une surface par hôte.
 *
 * D'où la nuance que la vue doit dire tout haut : sans DSN Temporal, le journal vit dans **ce**
 * processus. Une requête d'administration en ouvre un neuf, donc le catalogue est vide — et vide
 * est la réponse juste, pas une panne. C'est aussi la raison pour laquelle Magento n'a que deux
 * backends : l'hôte ne livre aucun des deux types de connexion auxquels les ponts SQL se lient,
 * donc l'état vit dans la grappe, ou il vit dans un processus.
 */
/*
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui l'étend.
 */
class ProcessHistory extends Template
{
    public function __construct(
        Context $context,
        private readonly RuntimeFactory $runtimeFactory,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Le journal de cet hôte vit-il dans ce processus ? Alors il est né avec cette requête et
     * mourra avec elle, et la grille sera vide — ce qui est la bonne réponse. Ce bloc ne rend plus
     * que cette phrase : les exécutions, elles, passent par la grille standard.
     */
    public function isEphemeral(): bool
    {
        return !$this->runtimeFactory->hasCluster();
    }

}
