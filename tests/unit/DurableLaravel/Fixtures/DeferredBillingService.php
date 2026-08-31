<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

/**
 * Un contrat dont une opération est servie tout de suite et l'autre remplie par un workflow.
 *
 * En un seul morceau, là où les contrats de la démonstration se séparent en deux interfaces : ce
 * qui compte ici est qu'un gestionnaire n'ait pas de méthode pour `settle`, et
 * `DeclaredNexusOperations` le lit par `method_exists()`, pas par la hiérarchie.
 */
#[AsNexusService('deferred-billing')]
interface DeferredBillingService
{
    #[AsNexusOperation('charge')]
    public function charge(int $amount): array;

    #[AsNexusOperation('settle')]
    public function settle(int $amount, string $currency): array;
}
