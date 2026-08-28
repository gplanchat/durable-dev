<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Demo\Contracts\Stock\StockContract;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Le métier demande à la boutique de retenir du stock, et attend son verdict.
 *
 * C'est le sens le plus simple des deux : l'opération est servie **tout de suite**, par une méthode
 * que la boutique a écrite. Rien ici ne le dit et rien ici ne le sait — ce workflow attend une
 * opération, et le résultat arrive quand il arrive. Si la boutique décidait demain de remplir
 * `reserver` par un workflow de son côté, cette classe ne changerait pas d'une ligne.
 *
 * Le contrat lu est `StockContract`, celui de l'appelant, et non `StockServed`, celui que le
 * gestionnaire implémente : l'appelant voit tout ce que le service expose, y compris ce qu'aucune
 * méthode ne sert.
 */
#[AsWorkflow(self::TYPE)]
final class ReserverStockWorkflow
{
    /**
     * L'endpoint dit *où* le service est servi — affaire de déploiement, pas de contrat.
     *
     * Il est créé par `bin/demo-nexus`, qui le fait pointer sur le namespace de la boutique et sur
     * la file que son worker poll.
     */
    public const ENDPOINT = 'demo-boutique-stock';

    /** Le nom que le serveur connaît — celui de `#[AsWorkflow]`, et non le FQCN. */
    public const TYPE = 'ReserverStockWorkflow';

    /** @var NexusStub<StockContract> */
    private readonly NexusStub $stock;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->stock = $environment->nexusStub(StockContract::class, endpoint: self::ENDPOINT);
    }

    /**
     * @param array<string, int> $lignes référence => quantité
     *
     * @return array{reserve: bool, manquants: array<string, int>}
     */
    #[AsWorkflowMethod]
    public function run(string $commande, array $lignes): array
    {
        return $this->environment->await($this->stock->reserver($commande, $lignes));
    }
}
