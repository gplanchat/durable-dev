<?php

declare(strict_types=1);

namespace App\Entity\Durable;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the shop has already answered for one order.
 *
 * It exists for a precise reason: **a Nexus task is redelivered**. The handler has about nine
 * seconds; past that the server hands the same task to another worker, and the redeliveries
 * measured land at ~9.9 s, ~20.7 s, ~33.6 s. With no record of what was already decided, the second
 * delivery would set stock aside a second time, and the caller would see nothing wrong: it would
 * have its answer.
 *
 * The key is therefore the order identifier, the one the caller writes into the payload: two
 * deliveries of the same task carry the same one, and the second reads back what the first decided.
 *
 * The property is `orderId` and not `order`: `order` is a reserved word in SQL, and Doctrine would
 * emit the column name unquoted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_durable_stock_reservation')]
class StockReservation
{
    /** @param array<string, int> $missing */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 128)]
        private string $orderId,
        #[ORM\Column(type: Types::JSON)]
        private array $lines,
        #[ORM\Column]
        private bool $reserved,
        #[ORM\Column(type: Types::JSON)]
        private array $missing,
    ) {}

    public function orderId(): string
    {
        return $this->orderId;
    }

    /** @return array<string, int> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return array{reserved: bool, missing: array<string, int>} */
    public function verdict(): array
    {
        return ['reserved' => $this->reserved, 'missing' => $this->missing];
    }
}
