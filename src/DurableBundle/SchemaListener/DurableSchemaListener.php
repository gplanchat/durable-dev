<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\SchemaListener;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
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
            self::isSameDatabase($connection),
        );
    }

    /**
     * Sonde « même base » : deux objets `Connection` distincts peuvent pointer la même base, et
     * seule une écriture le prouve. Le principe est celui de
     * `Symfony\Bridge\Doctrine\SchemaListener\AbstractSchemaListener::getIsSameDatabaseChecker()`,
     * dont la déclaration est identique de Symfony 6.4 à 8.0 — vérifié sur `v6.4.0`, `7.2` et
     * `8.0`. Elle est recopiée plutôt qu'héritée pour deux raisons : elle y est `protected`, donc
     * inaccessible sans étendre la classe, et l'étendre imposerait `symfony/doctrine-bridge` au
     * bundle pour vingt lignes qui ne dépendent que de la DBAL.
     *
     * @return \Closure(\Closure(string): mixed): bool
     */
    private static function isSameDatabase(Connection $connection): \Closure
    {
        return static function (\Closure $exec) use ($connection): bool {
            $checkTable = 'durable_schema_check_' . bin2hex(random_bytes(7));
            $connection->executeStatement(\sprintf('CREATE TABLE %s (id INTEGER NOT NULL)', $checkTable));

            try {
                $exec(\sprintf('DROP TABLE %s', $checkTable));
            } catch (\Exception) {
                // La connexion du journal n'a pas pu supprimer la table : soit une autre base,
                // soit un droit manquant. Le second contrôle tranche.
            }

            try {
                $connection->executeStatement(\sprintf('DROP TABLE %s', $checkTable));

                return false;
            } catch (TableNotFoundException) {
                return true;
            }
        };
    }
}
