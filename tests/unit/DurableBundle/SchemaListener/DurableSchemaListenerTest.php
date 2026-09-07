<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\SchemaListener;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Bundle\SchemaListener\DurableSchemaListener;
use PHPUnit\Framework\TestCase;

/**
 * Le chemin de production de la déclaration : l'écouteur, branché sur `postGenerateSchema`, avec
 * la vraie sonde « même base ».
 *
 * Les tests de `DurableSchema` passent la sonde en paramètre et prouvent donc la forme du schéma,
 * jamais la décision de déclarer. C'est cette décision qui rouvre ou ferme le constat : une sonde
 * qui répond toujours `false` laisse `doctrine:migrations:diff` regénérer ses `DROP TABLE` dès que
 * le journal a sa propre connexion.
 */
final class DurableSchemaListenerTest extends TestCase
{
    private const TABLES = [
        'durable_events',
        'durable_workflow_metadata',
        'durable_child_workflow_parent_link',
        'durable_workflow_runs',
    ];

    /** @var list<string> */
    private array $fichiers = [];

    protected function tearDown(): void
    {
        foreach ($this->fichiers as $fichier) {
            @unlink($fichier);
        }
        $this->fichiers = [];
    }

    public function testLaMemeConnexionDeclareLesTables(): void
    {
        $connection = self::enMemoire();
        $schema = new Schema();

        (new DurableSchemaListener(new DurableSchema($connection)))
            ->postGenerateSchema($this->evenement($connection, $schema));

        foreach (self::TABLES as $table) {
            self::assertTrue($schema->hasTable($table), \sprintf('%s doit être déclarée', $table));
        }
    }

    /**
     * Deux objets `Connection` distincts sur le même fichier : c'est le cas que la sonde existe
     * pour trancher, et celui qu'une sonde câblée sur `false` traitait comme une autre base.
     */
    public function testDeuxConnexionsSurLaMemeBaseDeclarentLesTables(): void
    {
        $fichier = $this->fichier();
        $journal = self::surFichier($fichier);
        $orm = self::surFichier($fichier);
        $schema = new Schema();

        (new DurableSchemaListener(new DurableSchema($journal)))
            ->postGenerateSchema($this->evenement($orm, $schema));

        self::assertCount(\count(self::TABLES), $schema->getTables(), 'la sonde doit reconnaître la même base');
    }

    public function testDeuxBasesDistinctesNeDeclarentRien(): void
    {
        $journal = self::surFichier($this->fichier());
        $orm = self::surFichier($this->fichier());
        $schema = new Schema();

        (new DurableSchemaListener(new DurableSchema($journal)))
            ->postGenerateSchema($this->evenement($orm, $schema));

        self::assertSame([], $schema->getTables(), 'aucune table ne doit rejoindre le schéma d\'une autre base');
    }

    private function evenement(Connection $connection, Schema $schema): GenerateSchemaEventArgs
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new GenerateSchemaEventArgs($em, $schema);
    }

    private static function enMemoire(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    private static function surFichier(string $chemin): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $chemin]);
    }

    private function fichier(): string
    {
        $chemin = \sprintf('%s/durable-schema-%s.sqlite', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $this->fichiers[] = $chemin;

        return $chemin;
    }
}
