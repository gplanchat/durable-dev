<?php

declare(strict_types=1);

namespace unit\DurableRector;

use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * Le set qui fait passer un projet d'une version de Durable à la suivante.
 *
 * `temporal-sdk.php` fait entrer un projet dans Durable ; celui-ci l'y fait avancer. Les deux
 * existent pour la même raison — un nom qui bouge sans procédure est une rupture qu'on découvre
 * en production —, et la règle du dépôt est explicite : Rector d'abord, script sinon, et de la
 * documentation dans tous les cas.
 */
final class DurableUpgradeSetTest extends AbstractRectorTestCase
{
    #[DataProvider('provideData')]
    public function testMovedClassesAreRewritten(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    public static function provideData(): \Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/DurableUpgrade');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/durable-upgrade.php';
    }
}
