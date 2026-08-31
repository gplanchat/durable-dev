<?php

declare(strict_types=1);

namespace App\Durable\Nexus;

use Gplanchat\Durable\Demo\Contracts\Livraison\LivraisonServed;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * La logistique répond à `livraison/planifier`, tout de suite.
 *
 * Elle implémente `LivraisonServed` et non `LivraisonContract` : un gestionnaire n'écrit que la part
 * du contrat à laquelle il répond sur la tâche. `expedier` n'a pas de méthode ici, et ce n'est pas
 * un oubli — {@see \App\Durable\Workflow\ExpedierWorkflow} la remplit.
 *
 * **Ce qui change par rapport aux deux autres maquettes n'est pas le code, c'est la déclaration.**
 * Symfony pose une balise depuis un attribut et une passe de compilation lit le contrat ; ici c'est
 * `config/durable.php` qui associe cette classe à son contrat, parce que le conteneur de Laravel n'a
 * pas d'autoconfiguration par attribut. La classe, elle, ne sait rien de tout cela.
 */
final readonly class LivraisonHandler implements LivraisonServed
{
    /** Ce que la logistique sait porter en une tournée. */
    private const COLIS_MAX = 5;

    public function __construct(
        private Cache $cache,
    ) {}

    /**
     * @param array<string, int> $lignes
     *
     * @return array{planifiee: bool, creneau: string, transporteur: string, motif: string|null}
     */
    public function planifier(string $commande, array $lignes): array
    {
        // Une tâche Nexus est redélivrée : la seconde livraison doit relire ce que la première a
        // décidé, pas décider une seconde fois — un créneau tiré deux fois ne serait pas le même.
        //
        // ponytail: le cache porte l'idempotence parce qu'un créneau est une décision reproductible
        // et sans effet de bord ; une vraie logistique la rangerait dans sa table d'expéditions, et
        // c'est là qu'il faudra la mettre le jour où la planification consomme une capacité.
        return $this->cache->rememberForever(
            'livraison:' . $commande,
            fn(): array => $this->decider($lignes),
        );
    }

    /**
     * @param array<string, int> $lignes
     *
     * @return array{planifiee: bool, creneau: string, transporteur: string, motif: string|null}
     */
    private function decider(array $lignes): array
    {
        $colis = array_sum(array_map(static fn($quantite): int => (int) $quantite, $lignes));

        if ($colis <= 0) {
            return [
                'planifiee' => false,
                'creneau' => '',
                'transporteur' => '',
                'motif' => 'rien à porter',
            ];
        }

        if ($colis > self::COLIS_MAX) {
            return [
                'planifiee' => false,
                'creneau' => '',
                'transporteur' => '',
                'motif' => \sprintf('%d colis, %d au plus par tournée', $colis, self::COLIS_MAX),
            ];
        }

        return [
            'planifiee' => true,
            // Le lendemain, en clair : la démonstration se lit dans un terminal, et une date ISO
            // dirait la même chose en moins lisible.
            'creneau' => date('Y-m-d', strtotime('+1 day')) . ' 09:00-12:00',
            'transporteur' => $colis > 2 ? 'messagerie' : 'coursier',
            'motif' => null,
        ];
    }
}
