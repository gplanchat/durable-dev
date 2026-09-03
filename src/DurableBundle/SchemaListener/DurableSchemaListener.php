<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\SchemaListener;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;

/**
 * Fait connaître les tables du journal à l'outillage Doctrine.
 *
 * Sans lui, `doctrine:schema:update` et `doctrine:migrations:diff` construisent le schéma attendu
 * à partir des seules entités, n'y trouvent pas les tables du pont, et les traitent en orphelines :
 * la migration générée **les supprime**. Un journal d'exécutions durables est exactement ce qu'on
 * ne veut pas voir disparaître dans une migration relue en diagonale.
 *
 * Le pendant amont est `MessengerTransportDoctrineSchemaListener`, qui existe pour la même raison
 * et à propos des mêmes tables gérées par une bibliothèque plutôt que par une entité.
 *
 * **Prudence délibérée sur la connexion.** Les tables ne sont déclarées que si le journal écrit sur
 * la connexion même que l'ORM inspecte. Deux objets `Connection` distincts peuvent pointer la même
 * base, et l'amont le prouve par une sonde ; cette sonde vit dans `AbstractSchemaListener`, dont
 * l'API a bougé à l'intérieur de la plage de versions que ce bundle accepte (^6.4 à ^8.0). Plutôt
 * que de s'y adosser au risque d'un appel fatal sur la version basse, on s'abstient : ne rien
 * déclarer laisse l'exploitant gérer ce schéma lui-même, là où déclarer à tort ferait créer des
 * tables dans la mauvaise base. {@see DurableSchema::configureSchema()} garde le paramètre de
 * sonde, et un hôte qui sait mieux peut le fournir.
 */
final class DurableSchemaListener
{
    public function __construct(
        private readonly DurableSchema $schema,
    ) {}

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $connection = $event->getEntityManager()->getConnection();

        $this->schema->configureSchema(
            $event->getSchema(),
            $connection,
            // Connexions distinctes : on ne tranche pas, donc on ne déclare pas. Voir le docbloc.
            static fn(): bool => false,
        );
    }
}
