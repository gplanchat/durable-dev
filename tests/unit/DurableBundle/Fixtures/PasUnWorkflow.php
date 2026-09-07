<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

/**
 * Ne porte pas l'attribut : ne doit pas rejoindre le registre.
 */
final class PasUnWorkflow
{
    public function run(): string
    {
        return 'non';
    }
}
