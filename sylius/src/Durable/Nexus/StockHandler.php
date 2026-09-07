<?php

declare(strict_types=1);

namespace App\Durable\Nexus;

use App\Entity\Durable\StockReservation;
use App\Entity\Product\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use Gplanchat\Durable\Demo\Contracts\Stock\StockServed;

/**
 * The shop answers `stock/reserve`, out of its own stock model.
 *
 * It implements `StockServed` and not `StockContract`: a handler implements only the part of the
 * contract it answers **right away**. The two still coincide here, but the line is already in place
 * for the day the caller sees an operation the shop fulfils with a workflow.
 *
 * The `durable.nexus_handler` tag is set by `config/services.yaml`, under `when@demo`, and not by
 * `#[AsNexusServiceHandler]`. The attribute would hold in every environment, and the shop only has a
 * cluster in its demonstration profile: a handler declared with no route is refused at boot, and
 * rightly so. `symfony/` keeps the attribute, its bench having a DSN everywhere.
 *
 * The budget is about nine seconds: the task's, not the operation's. Two indexed reads and one
 * write fit in it; a call to a provider does not, and that is what `#[FulfilsNexusOperation]` exists
 * to carry.
 */
final readonly class StockHandler implements StockServed
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<string, int> $lines
     *
     * @return array{reserved: bool, missing: array<string, int>}
     */
    public function reserve(string $order, array $lines): array
    {
        $already = $this->entityManager->find(StockReservation::class, $order);
        if (null !== $already) {
            // A redelivery: the first delivery has already decided, and setting stock aside a second
            // time would be invisible to the caller, which would have its answer.
            return $already->verdict();
        }

        $missing = [];
        $toHold = [];

        foreach ($lines as $reference => $quantity) {
            $variant = $this->entityManager->getRepository(ProductVariant::class)
                ->findOneBy(['code' => (string) $reference]);

            if (null === $variant) {
                // A reference the shop does not know is missing entirely: saying "zero available" is
                // more useful to the caller than an error, which would be retried.
                $missing[(string) $reference] = (int) $quantity;

                continue;
            }

            if (!$variant->isTracked()) {
                continue;
            }

            $available = (int) $variant->getOnHand() - (int) $variant->getOnHold();
            if ($available < (int) $quantity) {
                $missing[(string) $reference] = (int) $quantity - max(0, $available);

                continue;
            }

            $toHold[] = [$variant, (int) $quantity];
        }

        $reserved = [] === $missing;
        if ($reserved) {
            // All or nothing: a partial reservation would leave the caller deciding what to do with
            // half a basket, and the contract gives it no way to say so.
            foreach ($toHold as [$variant, $quantity]) {
                $variant->setOnHold((int) $variant->getOnHold() + $quantity);
            }
        }

        $this->entityManager->persist(new StockReservation($order, $lines, $reserved, $missing));
        $this->entityManager->flush();

        return ['reserved' => $reserved, 'missing' => $missing];
    }
}
