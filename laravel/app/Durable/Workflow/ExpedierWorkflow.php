<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;
use Gplanchat\Durable\Demo\Contracts\Livraison\LivraisonContract;
use Gplanchat\Durable\Demo\Contracts\Stock\StockContract;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Ce qui remplit `livraison/expedier`, et qui appelle à son tour une troisième application.
 *
 * Deux choses tiennent dans cette classe, et la seconde est celle qu'aucune autre maquette ne
 * montre :
 *
 * 1. **Elle remplit une opération.** Il n'y a pas de méthode de gestionnaire pour `expedier` :
 *    la plomberie démarre ce workflow avec le callback de la tâche attaché, et le serveur livre son
 *    résultat à l'appelant quand il se termine. Six secondes de préparation en entrepôt suffisent à
 *    le montrer — au-delà du budget d'une tâche Nexus, en deçà de la patience d'un lecteur.
 * 2. **Elle appelle pendant qu'elle sert.** Avant de sortir la marchandise, la logistique
 *    redemande son verdict à la boutique, par `stock/reserver`, sur un endpoint qui n'est pas le
 *    sien. L'appel est sans risque parce que `reserver` est idempotente par identifiant de
 *    commande : la boutique **relit** la décision prise à la commande au lieu d'en prendre une
 *    nouvelle — c'est pourquoi les lignes passées ici sont vides.
 *
 * L'exécution qui en résulte porte donc une opération Nexus **servie** et une opération Nexus
 * **appelée**, dans le même journal, chez le même hôte, et cet hôte est Laravel.
 *
 * ⚠ **Les noms de paramètres sont l'interface.** `commande` et `creneau` sont ceux que
 * `LivraisonContract::expedier()` déclare, et la charge est clée par nom des deux côtés. En
 * renommer un ici sans le renommer là-bas fait refuser l'enregistrement, en nommant les deux
 * signatures : `NexusFulfilmentParameterNames` est appelée par `DeclaredNexusOperations` comme elle
 * l'est par la passe de compilation de Symfony.
 */
#[AsWorkflow(self::TYPE)]
#[FulfilsNexusOperation(LivraisonContract::class, 'expedier')]
final class ExpedierWorkflow
{
    public const TYPE = 'ExpedierWorkflow';

    /** L'endpoint de la boutique, celui-là même que les autres appelants utilisent. */
    public const ENDPOINT_STOCK = 'demo-boutique-stock';

    /** @var NexusStub<StockContract> */
    private readonly NexusStub $stock;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->stock = $environment->nexusStub(StockContract::class, endpoint: self::ENDPOINT_STOCK);
    }

    /**
     * @return array{expediee: bool, suivi: string}
     */
    #[AsWorkflowMethod]
    public function run(string $commande, string $creneau): array
    {
        // La préparation en entrepôt. `sleep()` attend ; `timer()` rend un awaitable qu'il faut
        // attendre — la confusion entre les deux a déjà produit un `TimerStarted` sans `TimerFired`
        // dans ce dépôt.
        $this->environment->sleep(6.0, 'préparation en entrepôt');

        // Lignes vides : on ne demande pas une nouvelle réservation, on relit celle que
        // l'identifiant de commande a déjà décidée chez la boutique.
        $verdict = $this->environment->await($this->stock->reserver($commande, []));

        if (true !== ($verdict['reserve'] ?? false)) {
            // Le stock n'est plus tenu : rien ne sort, et l'appelant l'apprend par le résultat de
            // l'opération plutôt que par une exception — c'est une issue métier, pas une panne.
            return ['expediee' => false, 'suivi' => ''];
        }

        return [
            'expediee' => true,
            'suivi' => 'TRK-' . strtoupper(substr(md5($commande . $creneau), 0, 10)),
        ];
    }
}
