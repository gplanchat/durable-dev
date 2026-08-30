<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow;

use Gplanchat\Durable\Demo\Contracts\Facturation\FacturationContract;
use Gplanchat\Durable\Demo\Contracts\Stock\StockContract;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Magento passe une commande dont **rien n'est servi par Magento**.
 *
 * Le stock est retenu par la boutique Sylius, la facture est vérifiée puis encaissée par le métier
 * Symfony. Trois applications, trois namespaces, et celle-ci n'a ni gestionnaire Nexus, ni file de
 * tâches Nexus, ni passe de compilation qui enregistrerait quoi que ce soit — parce qu'**appeler
 * n'en demande aucun**. `WorkflowEnvironment::nexusStub()` lit le contrat par réflexion, et le
 * worker qui fait avancer cette exécution est le même `WorkflowTaskRunner` que les deux autres
 * maquettes tournent sous un autre nom.
 *
 * Les deux formes de réponse sont ici, comme dans `CommandeWorkflow` de la boutique : `verifier` et
 * `reserver` reviennent sur la tâche, `encaisser` est remplie par un workflow d'en face et prend
 * une quinzaine de secondes. Rien dans ce fichier ne dit laquelle est laquelle.
 *
 * ⚠ **Le garde de nommage ne couvre pas cet hôte.** La règle « tout paramètre d'un workflow qui
 * remplit une opération doit être un paramètre du contrat » vit dans `NexusHandlerPass`, donc dans
 * le conteneur de Symfony. Elle garde le **servant**, et Magento ne sert rien : ici, ce qui compte
 * est que les noms passés aux méthodes du stub soient ceux du contrat, ce que la signature typée du
 * contrat vérifie déjà.
 */
final class CommandeNexusWorkflow
{
    /** L'endpoint de la boutique, créé par `bin/demo-nexus`. */
    public const ENDPOINT_STOCK = 'demo-boutique-stock';

    /** Celui du métier. */
    public const ENDPOINT_FACTURATION = 'demo-metier-facturation';

    /** @var NexusStub<StockContract> */
    private readonly NexusStub $stock;

    /** @var NexusStub<FacturationContract> */
    private readonly NexusStub $facturation;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->stock = $environment->nexusStub(StockContract::class, endpoint: self::ENDPOINT_STOCK);
        $this->facturation = $environment->nexusStub(FacturationContract::class, endpoint: self::ENDPOINT_FACTURATION);
    }

    /**
     * @param array<string, int> $lignes  référence => quantité
     * @param int                $montant en centimes
     *
     * @return array{
     *     verifiee: array{acceptee: bool, motif: string|null},
     *     reservation: array{reserve: bool, manquants: array<string, int>}|null,
     *     encaissement: array{recu: string, encaisse: int}|null
     * }
     */
    #[AsWorkflowMethod]
    public function run(string $commande, array $lignes, int $montant, string $devise = 'EUR'): array
    {
        // ⚠ **L'ordre des trois appels est ce qui dispense de compenser**, et il a été mesuré à
        // l'envers d'abord : retenir le stock avant de vérifier la facture laissait `MUG_BLUE`
        // retenu chez la boutique après un refus de devise, sans que rien ne le libère. Le contrat
        // `stock` n'a pas d'opération qui rende ce qu'il a pris — vérifier d'abord fait qu'il n'y a
        // rien à rendre.
        $verdict = $this->environment->await($this->facturation->verifier($commande, $montant, $devise));

        if (true !== ($verdict['acceptee'] ?? false)) {
            return ['verifiee' => $verdict, 'reservation' => null, 'encaissement' => null];
        }

        $reservation = $this->environment->await($this->stock->reserver($commande, $lignes));

        if (true !== ($reservation['reserve'] ?? false)) {
            return ['verifiee' => $verdict, 'reservation' => $reservation, 'encaissement' => null];
        }

        return [
            'verifiee' => $verdict,
            'reservation' => $reservation,
            'encaissement' => $this->environment->await($this->facturation->encaisser($commande, $montant, $devise)),
        ];
    }
}
