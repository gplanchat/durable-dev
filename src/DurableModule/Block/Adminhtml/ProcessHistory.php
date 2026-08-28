<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Block\Adminhtml;

use Gplanchat\Durable\Magento\Runtime\RuntimeFactory;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
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
    /** @var list<WorkflowRunDescription>|null */
    private ?array $runs = null;

    private bool $ephemeral = true;

    public function __construct(
        Context $context,
        private readonly RuntimeFactory $runtimeFactory,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return list<WorkflowRunDescription>
     */
    public function getRuns(): array
    {
        if ($this->runs === null) {
            $store = $this->runtimeFactory->create()->eventStore();
            $this->ephemeral = $store instanceof InMemoryEventStore;
            $this->runs = (new InMemoryWorkflowRunCatalog($store))->listRuns(limit: 50)->runs;
        }

        return $this->runs;
    }

    /**
     * Le journal vit-il dans ce processus ? Alors il est né avec cette requête et mourra avec elle.
     */
    public function isEphemeral(): bool
    {
        $this->getRuns();

        return $this->ephemeral;
    }

    public function formatMoment(?\DateTimeImmutable $moment): string
    {
        return $moment === null ? '—' : $moment->format('Y-m-d H:i:s');
    }
}
