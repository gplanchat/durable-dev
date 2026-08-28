<?php

declare(strict_types=1);

namespace unit\DurableRector;

use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * L'alignement des attributs de déclaration pour v0.1.0-alpha8.
 *
 * Les attributs de méthode y passent aussi : `method_attributes_too` couvre les trois que ni un
 * workflow ni un contrat d'activité n'amènent — query, signal, update — parce qu'un jeu qui ne
 * prouverait le renommage que sur les deux premiers laisserait les trois autres se casser en
 * silence chez qui les utilise.
 *
 * **Pourquoi les fixtures montrent des noms pleinement qualifiés.** `RenameClassRector` écrit le
 * FQCN et laisse le `use` d'origine en place tant que `withImportNames()` n'est pas activé — et il
 * ne l'est pas ici, la configuration de test n'appliquant que le set. C'est la sortie brute de la
 * règle, et c'est elle qu'il faut figer : décrire une sortie plus jolie que celle qu'on obtient
 * ferait passer un jeu vert sur un comportement qu'on n'a pas.
 *
 * Le `rector.php` proposé aux consommateurs, lui, active `withImportNames()` — c'est écrit dans le
 * README du paquet, avec le reste du set.
 */
final class DurableAttributesAlpha8RectorTest extends AbstractRectorTestCase
{
    #[DataProvider('provideData')]
    public function testEveryDeclarationAttributeGainsItsPrefix(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    public static function provideData(): \Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/AttributesAlpha8');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/attributes-alpha8.php';
    }
}
