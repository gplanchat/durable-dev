<?php

declare(strict_types=1);

namespace App\Durable\Nexus;

use App\Entity\Durable\StockReservation;
use App\Entity\Product\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use Gplanchat\Durable\Demo\Contracts\Stock\StockServed;

/**
 * La boutique répond à `stock/reserver`, depuis son propre modèle de stock.
 *
 * Elle implémente `StockServed` et non `StockContract` : un gestionnaire n'implémente que la part
 * du contrat à laquelle il répond **tout de suite**. Ici les deux se confondent encore, mais la
 * ligne est déjà à sa place pour le jour où l'appelant verra une opération que la boutique remplira
 * par un workflow.
 *
 * La balise `durable.nexus_handler` est posée par `config/services.yaml`, sous `when@demo`, et non
 * par `#[AsNexusServiceHandler]`. L'attribut vaudrait dans tous les environnements, et la boutique
 * n'a de cluster que dans son profil de démonstration : un gestionnaire déclaré sans route est
 * refusé au démarrage, à raison. `symfony/` garde l'attribut, son banc ayant un DSN partout.
 *
 * Le budget est d'environ neuf secondes — celui de la tâche, pas celui de l'opération. Deux lectures
 * indexées et une écriture y tiennent ; un appel à un prestataire, non, et c'est ce que
 * `#[FulfilsNexusOperation]` existe pour porter.
 */
final readonly class StockHandler implements StockServed
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<string, int> $lignes
     *
     * @return array{reserve: bool, manquants: array<string, int>}
     */
    public function reserver(string $commande, array $lignes): array
    {
        $deja = $this->entityManager->find(StockReservation::class, $commande);
        if (null !== $deja) {
            // Redélivrance : la première livraison a déjà décidé, et remettre du stock de côté une
            // seconde fois serait invisible pour l'appelant, qui aurait sa réponse.
            return $deja->verdict();
        }

        $manquants = [];
        $aTenir = [];

        foreach ($lignes as $reference => $quantite) {
            $variante = $this->entityManager->getRepository(ProductVariant::class)
                ->findOneBy(['code' => (string) $reference]);

            if (null === $variante) {
                // Une référence que la boutique ne connaît pas manque entièrement : dire « zéro
                // disponible » est plus utile à l'appelant qu'une erreur, qui serait réessayée.
                $manquants[(string) $reference] = (int) $quantite;

                continue;
            }

            if (!$variante->isTracked()) {
                continue;
            }

            $disponible = (int) $variante->getOnHand() - (int) $variante->getOnHold();
            if ($disponible < (int) $quantite) {
                $manquants[(string) $reference] = (int) $quantite - max(0, $disponible);

                continue;
            }

            $aTenir[] = [$variante, (int) $quantite];
        }

        $reserve = [] === $manquants;
        if ($reserve) {
            // Tout ou rien : une réservation partielle laisserait l'appelant décider quoi faire
            // d'un demi-panier, et le contrat ne lui donne pas de quoi le dire.
            foreach ($aTenir as [$variante, $quantite]) {
                $variante->setOnHold((int) $variante->getOnHold() + $quantite);
            }
        }

        $this->entityManager->persist(new StockReservation($commande, $lignes, $reserve, $manquants));
        $this->entityManager->flush();

        return ['reserve' => $reserve, 'manquants' => $manquants];
    }
}
