<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\SchemaListener;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;

/**
 * Makes the journal's tables known to the Doctrine tooling.
 *
 * Without it, `doctrine:schema:update` and `doctrine:migrations:diff` build the expected schema
 * from the entities alone, do not find the bridge's tables there, and treat them as orphans: the
 * generated migration **drops them**. A journal of durable executions is exactly what nobody wants
 * to see disappear in a migration read at a glance.
 *
 * The upstream counterpart is `MessengerTransportDoctrineSchemaListener`, which exists for the same
 * reason and about the same kind of table, held by a library rather than by an entity.
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
     * The "same database" probe: two distinct `Connection` objects can point at one database, and
     * only a write proves it. The principle is that of
     * `Symfony\Bridge\Doctrine\SchemaListener\AbstractSchemaListener::getIsSameDatabaseChecker()`,
     * whose declaration is identical from Symfony 6.4 to 8.0, checked on `v6.4.0`, `7.2` and `8.0`.
     * It is copied rather than inherited for two reasons: it is `protected` there, so unreachable
     * without extending the class, and extending it would impose `symfony/doctrine-bridge` on the
     * bundle for twenty lines that depend on the DBAL alone.
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
                // The journal's connection could not drop the table: either another database, or a
                // missing privilege. The second check settles it.
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
