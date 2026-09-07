<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use PHPUnit\Framework\TestCase;

/**
 * Les tables du journal doivent être **déclarables** à l'outillage Doctrine, pas seulement
 * créables par le pont.
 *
 * Une table que `doctrine:migrations:diff` ne voit pas dans le schéma attendu est une table
 * orpheline : la migration générée la supprime. Le journal d'exécutions durables est exactement
 * ce qu'on ne veut pas voir disparaître dans une migration que personne n'a relue de près.
 *
 * @see DUR030
 */
final class DurableSchemaDeclarationTest extends TestCase
{
    private const TABLES = [
        'durable_events',
        'durable_workflow_metadata',
        'durable_child_workflow_parent_link',
        'durable_workflow_runs',
    ];

    public function testLesTablesSontDeclareesDansUnSchemaVide(): void
    {
        $connection = self::connection();
        $schema = new Schema();

        (new DurableSchema($connection))->configureSchema($schema, $connection, static fn(): bool => true);

        foreach (self::TABLES as $table) {
            self::assertTrue($schema->hasTable($table), \sprintf('%s doit être déclarée', $table));
        }
    }

    /**
     * Le schéma que Doctrine construit porte déjà les tables des entités, et peut porter les
     * nôtres si une migration précédente les a créées. Redéclarer une table présente lèverait.
     */
    public function testUneTableDejaPresenteDansLeSchemaNEstPasRedeclaree(): void
    {
        $connection = self::connection();
        $schema = new Schema();
        $dejaLa = $schema->createTable('durable_events');
        $dejaLa->addColumn('id', 'bigint');

        (new DurableSchema($connection))->configureSchema($schema, $connection, static fn(): bool => true);

        self::assertTrue($schema->hasTable('durable_events'));
        self::assertTrue($schema->hasTable('durable_workflow_runs'), 'les autres sont déclarées quand même');
    }

    /**
     * Le journal peut vivre sur une autre connexion que celle de l'ORM. Y déclarer nos tables
     * ferait créer, dans la base de l'application, des tables qui n'y sont pas — et supprimer,
     * dans la base du journal, celles qui y sont.
     */
    public function testRienNEstDeclareQuandCeNEstPasLaMemeBase(): void
    {
        $schema = new Schema();

        (new DurableSchema(self::connection()))->configureSchema(
            $schema,
            self::connection(),
            static fn(): bool => false,
        );

        self::assertSame([], $schema->getTables(), 'aucune table ne doit rejoindre le schéma d\'une autre base');
    }

    public function testLaMemeBaseSurUneAutreConnexionEstDeclaree(): void
    {
        $schema = new Schema();

        (new DurableSchema(self::connection()))->configureSchema(
            $schema,
            self::connection(),
            static fn(): bool => true,
        );

        self::assertCount(\count(self::TABLES), $schema->getTables());
    }

    /**
     * Quand les migrations tiennent le schéma, le DDL paresseux du pont n'a plus lieu d'être :
     * il écrirait derrière le dos de l'outil qui en a désormais la charge.
     */
    public function testAutoSetupDesactiveNeCreeAucuneTable(): void
    {
        $connection = self::connection();

        (new DurableSchema($connection, autoSetup: false))->ensure();

        self::assertSame([], $connection->createSchemaManager()->listTableNames());
    }

    public function testAutoSetupActifCreeLesTables(): void
    {
        $connection = self::connection();

        (new DurableSchema($connection))->ensure();

        foreach (self::TABLES as $table) {
            self::assertContains($table, $connection->createSchemaManager()->listTableNames());
        }
    }

    private static function connection(): \Doctrine\DBAL\Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }
}
