<?php

declare(strict_types=1);

namespace App\Entity\Durable;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce que la boutique a déjà répondu pour une commande.
 *
 * Elle existe pour une raison précise : **une tâche Nexus est redélivrée**. Le gestionnaire dispose
 * d'environ neuf secondes ; passé ce délai le serveur redonne la même tâche à un autre worker, et
 * les redélivrances mesurées tombent à ~9,9 s, ~20,7 s, ~33,6 s. Sans trace de ce qui a déjà été
 * décidé, la deuxième livraison remettrait du stock de côté une deuxième fois, et l'appelant ne
 * verrait rien d'anormal — il aurait sa réponse.
 *
 * La clé est donc l'identifiant de commande, celui que l'appelant écrit dans la charge : deux
 * livraisons de la même tâche portent le même, et la seconde relit ce que la première a décidé.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_durable_stock_reservation')]
class StockReservation
{
    /** @param array<string, int> $manquants */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 128)]
        private string $commande,
        #[ORM\Column(type: Types::JSON)]
        private array $lignes,
        #[ORM\Column]
        private bool $reserve,
        #[ORM\Column(type: Types::JSON)]
        private array $manquants,
    ) {}

    public function commande(): string
    {
        return $this->commande;
    }

    /** @return array<string, int> */
    public function lignes(): array
    {
        return $this->lignes;
    }

    /** @return array{reserve: bool, manquants: array<string, int>} */
    public function verdict(): array
    {
        return ['reserve' => $this->reserve, 'manquants' => $this->manquants];
    }
}
