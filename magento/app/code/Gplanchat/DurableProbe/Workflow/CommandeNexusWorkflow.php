<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow;

use Gplanchat\Durable\Demo\Contracts\Facturation\FacturationContract;
use Gplanchat\Durable\Demo\Contracts\Livraison\LivraisonContract;
use Gplanchat\Durable\Demo\Contracts\Stock\StockContract;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Magento passe une commande dont **rien n'est servi par Magento**.
 *
 * Le stock est retenu par la boutique Sylius, la facture est vérifiée puis encaissée par le métier
 * Symfony, et l'expédition est planifiée puis faite par la logistique Laravel. Quatre applications,
 * quatre namespaces, trois frameworks, et celle-ci n'a ni gestionnaire Nexus, ni file de tâches
 * Nexus, ni passe de compilation qui enregistrerait quoi que ce soit — parce qu'**appeler n'en
 * demande aucun**. `WorkflowEnvironment::nexusStub()` lit le contrat par réflexion, et le
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

    /** Celui de la logistique. */
    public const ENDPOINT_LIVRAISON = 'demo-laravel-livraison';

    /** @var NexusStub<StockContract> */
    private readonly NexusStub $stock;

    /** @var NexusStub<FacturationContract> */
    private readonly NexusStub $facturation;

    /** @var NexusStub<LivraisonContract> */
    private readonly NexusStub $livraison;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->stock = $environment->nexusStub(StockContract::class, endpoint: self::ENDPOINT_STOCK);
        $this->facturation = $environment->nexusStub(FacturationContract::class, endpoint: self::ENDPOINT_FACTURATION);
        $this->livraison = $environment->nexusStub(LivraisonContract::class, endpoint: self::ENDPOINT_LIVRAISON);
    }

    /**
     * @param array<string, int> $lignes  référence => quantité
     * @param int                $montant en centimes
     *
     * @return array{
     *     verifiee: array{acceptee: bool, motif: string|null},
     *     reservation: array{reserve: bool, manquants: array<string, int>}|null,
     *     encaissement: array{recu: string, encaisse: int}|null,
     *     livraison: array{planifiee: bool, creneau: string, transporteur: string, motif: string|null}|null,
     *     expedition: array{expediee: bool, suivi: string}|null
     * }
     */
    #[AsWorkflowMethod]
    public function run(string $commande, array $lignes, int $montant, string $devise = 'EUR'): array
    {
        // ⚠ **L'ordre des cinq appels est ce qui dispense de compenser**, et les deux inversions
        // ont été mesurées avant d'être corrigées :
        //
        // - retenir le stock avant de vérifier la facture laissait `MUG_BLUE` retenu chez la
        //   boutique après un refus de devise ;
        // - encaisser avant de planifier la tournée faisait payer une commande que la logistique
        //   refusait ensuite de porter.
        //
        // Aucun des trois contrats n'a d'opération qui rende ce qu'il a pris. **Demander d'abord
        // tout ce qui peut dire non, n'engager qu'ensuite** : c'est l'ordre qui est la
        // compensation, faute d'en avoir une.
        $verdict = $this->environment->await($this->facturation->verifier($commande, $montant, $devise));

        if (true !== ($verdict['acceptee'] ?? false)) {
            return self::rien($verdict);
        }

        $livraison = $this->environment->await($this->livraison->planifier($commande, $lignes));

        if (true !== ($livraison['planifiee'] ?? false)) {
            return array_merge(self::rien($verdict), ['livraison' => $livraison]);
        }

        $reservation = $this->environment->await($this->stock->reserver($commande, $lignes));

        if (true !== ($reservation['reserve'] ?? false)) {
            return array_merge(self::rien($verdict), [
                'livraison' => $livraison,
                'reservation' => $reservation,
            ]);
        }

        // Les deux engagements, une fois que les trois refus possibles ont été écartés.
        // `encaisser` est remplie par un workflow du métier, `expedier` par un workflow de la
        // logistique — qui, lui, rappelle la boutique pendant qu'il sert. Trois hôtes, trois
        // frameworks, et le même `await` pour les cinq appels.
        return [
            'verifiee' => $verdict,
            'reservation' => $reservation,
            'encaissement' => $this->environment->await($this->facturation->encaisser($commande, $montant, $devise)),
            'livraison' => $livraison,
            'expedition' => $this->environment->await(
                $this->livraison->expedier($commande, $livraison['creneau']),
            ),
        ];
    }

    /**
     * Le résultat d'une commande qui s'arrête avant d'avoir rien coûté à personne.
     *
     * @param array{acceptee: bool, motif: string|null} $verdict
     *
     * @return array{verifiee: array{acceptee: bool, motif: string|null}, reservation: null, encaissement: null, livraison: null, expedition: null}
     */
    private static function rien(array $verdict): array
    {
        return [
            'verifiee' => $verdict,
            'reservation' => null,
            'encaissement' => null,
            'livraison' => null,
            'expedition' => null,
        ];
    }
}
