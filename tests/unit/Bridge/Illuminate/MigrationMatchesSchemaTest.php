<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Les deux façons de créer les tables doivent créer les mêmes.
 *
 * Le pont en a deux : {@see DurableSchema} les monte à la demande — ce dont vivent les tests et un
 * worker qui démarre sur une base vide — et la migration publiée les monte par `php artisan
 * migrate`, ce dont vivra une application. Deux chemins vers le même schéma, c'est deux occasions
 * de diverger, et la divergence ne se verrait ni à la migration ni au test : elle se verrait en
 * production, sur une colonne absente ou trop courte.
 *
 * Ce test monte les deux sur deux connexions et compare colonne par colonne.
 */
final class MigrationMatchesSchemaTest extends TestCase
{
    private const TABLES = [
        'durable_events',
        'durable_workflow_metadata',
        'durable_workflow_runs',
        'durable_child_workflow_parent_link',
    ];

    /**
     * La comparaison se fait sur le **DDL**, pas sur l'introspection.
     *
     * SQLite jette les longueurs : une colonne déclarée `varchar(32)` s'y relit `varchar`, et
     * `status` raccourci à huit caractères passait donc inaperçu — en tronquant
     * `continued_as_new`, dix-huit caractères, dès qu'une vraie application tourne sur MySQL.
     * Laravel sait rendre le DDL sans l'exécuter et sans serveur : la grammaire MySQL, elle, porte
     * les longueurs **et** les index.
     */
    public function testTheMigrationAndTheOnDemandSchemaEmitTheSameDdl(): void
    {
        self::assertSame(
            self::ddl(static fn(Connection $connection) => (new DurableSchema($connection))->ensure()),
            self::ddl(static fn(Connection $connection) => self::migration($connection)->up()),
            'la migration et le schéma à la demande ne montent pas les mêmes tables',
        );
    }

    public function testTheMigrationCreatesEveryTableTheStoresWrite(): void
    {
        $migrated = self::byMigration();

        foreach (self::TABLES as $table) {
            self::assertTrue(
                $migrated->getSchemaBuilder()->hasTable($table),
                \sprintf('la migration oublie %s', $table),
            );
        }
    }

    public function testTheMigrationRollsBackWhatItCreated(): void
    {
        $connection = self::connection();
        $migration = self::migration($connection);
        $migration->up();
        $migration->down();

        foreach (self::TABLES as $table) {
            self::assertFalse(
                $connection->getSchemaBuilder()->hasTable($table),
                \sprintf('%s survit à down()', $table),
            );
        }
    }

    private static function connection(): Connection
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        return $capsule->getConnection();
    }

    /**
     * La migration parle par la façade `Schema`. Hors application Laravel, il faut donc lui donner
     * un conteneur — c'est tout ce que `Facade::setFacadeApplication()` demande, et c'est aussi ce
     * qui rend ce test possible sans monter une application entière.
     */
    private static function migration(Connection $connection): object
    {
        $container = new Container();
        $container->instance('db.schema', $connection->getSchemaBuilder());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        return require __DIR__ . '/../../../../src/Bridge/Illuminate/Migrations/0001_01_01_000000_create_durable_tables.php';
    }

    private static function byMigration(): Connection
    {
        $connection = self::connection();
        self::migration($connection)->up();

        return $connection;
    }

    private static function byDurableSchema(): Connection
    {
        $connection = self::connection();
        (new DurableSchema($connection))->ensure();

        return $connection;
    }

    /**
     * Le DDL rendu par les deux chemins, sur une connexion MySQL jamais ouverte : `pretend()`
     * n'exécute rien et `select()` rend un tableau vide, donc `hasTable()` conclut que rien
     * n'existe et les quatre tables sont émises.
     *
     * @param callable(Connection): void $build
     *
     * @return list<string>
     */
    private static function ddl(callable $build): array
    {
        $capsule = new Manager();
        $capsule->addConnection([
            'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'unopened',
            'username' => 'none', 'password' => '', 'prefix' => '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        ], 'ddl');
        $connection = $capsule->getConnection('ddl');

        $queries = $connection->pretend(static function () use ($connection, $build): void {
            $build($connection);
        });

        // `pretend()` journalise **toutes** les requêtes, lectures comprises : les quatre
        // `hasTable()` de `DurableSchema` arriveraient ici comme quatre `select` de plus, et la
        // comparaison porterait sur le chemin emprunté plutôt que sur les tables obtenues. Seul
        // le DDL compte.
        return array_values(array_filter(
            array_map(static fn(array $query): string => $query['query'], $queries),
            static fn(string $query): bool => (bool) preg_match('/^(create|alter|drop)\s/i', $query),
        ));
    }

    /**
     * Le nom d'une colonne ne suffit pas. `status` raccourci de 32 à 8 caractères passait la
     * comparaison des seuls noms — et tronquait `continued_as_new`, dix-huit caractères, sur
     * MySQL. Le type déclaré et la nullabilité entrent donc dans la comparaison.
     *
     * @return array<string, string>
     */
    private static function columns(Connection $connection, string $table): array
    {
        $shape = [];
        foreach ($connection->getSchemaBuilder()->getColumns($table) as $column) {
            $shape[(string) $column['name']] = \sprintf(
                '%s%s',
                $column['type'] ?? $column['type_name'] ?? '?',
                ($column['nullable'] ?? false) ? ' null' : '',
            );
        }
        ksort($shape);

        return $shape;
    }
}
