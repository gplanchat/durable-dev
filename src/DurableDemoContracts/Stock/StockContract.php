<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Demo\Contracts\Stock;

use Gplanchat\Durable\Attribute\AsNexusService;

/**
 * Ce que l'appelant voit du service `stock`.
 *
 * Elle n'ajoute rien pour l'instant, et c'est volontaire. La séparation n'existe pas pour ce
 * qu'elle sépare aujourd'hui mais pour ce qu'elle laisse ajouter demain : une opération remplie par
 * un workflow se déclare **ici**, où le gestionnaire n'a pas à lui écrire de corps vide.
 *
 * L'appelant lit donc toujours `StockContract`, y compris tant que les deux interfaces portent les
 * mêmes opérations — sans quoi le jour où l'une diverge, c'est chaque appelant qu'il faudrait
 * retoucher.
 */
#[AsNexusService('stock')]
interface StockContract extends StockServed {}
