<?php

declare(strict_types=1);

namespace integration\Temporal\Fixtures;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * Les activités que la suite d'intégration planifie contre un vrai serveur.
 *
 * Distinct du contrat des tests unitaires, et pas par symétrie : les deux suites vivent dans des
 * espaces de noms séparés, et surtout ce qui part sur le fil ici est observé par un serveur
 * Temporal. Le nom transmis est celui de l'attribut — il ne doit pas bouger, sinon ce sont les
 * historiques déjà enregistrés qui cessent de rejouer.
 *
 * Le nom du paramètre est la clé de la charge : `ActivityStub` la construit depuis les
 * paramètres déclarés ici.
 */
interface IntegrationActivities
{
    #[AsActivityMethod('double')]
    public function double(int $value): int;

    #[AsActivityMethod('append')]
    public function append(string $text): string;

    #[AsActivityMethod('refund')]
    public function refund(string $order): string;

    /** Échoue toujours : c'est le sujet des workflows FailsOnActivity, UnboundedRetry et NonRetryable. */
    #[AsActivityMethod('boom')]
    public function boom(): never;
}
