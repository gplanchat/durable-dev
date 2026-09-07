<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\RecordedDetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les quatre façons dont un aller-retour JSON naïf trahit — chacune constatée en exécution avant
 * d'être écrite ici.
 *
 * Une barrière de stockage se juge sur ce qu'elle fait des entrées hostiles, pas sur ce qu'elle
 * fait des entrées ordinaires. Trois de ces cas passaient à travers.
 */
final class RecordedDetailsStorableTest extends TestCase
{
    /**
     * `json_encode` appelle le `jsonSerialize()` de la charge utile : du code métier, qui peut
     * lever. Aucun drapeau ne couvre ce cas, et l'exception remonterait jusqu'à `collect()`,
     * c'est-à-dire `kernel.response` — la requête tombe, alors que le défaut d'origine ne
     * cassait que l'écriture du profil sur `kernel.terminate`.
     */
    public function testUneChargeUtileDontLaSerialisationLeveNeFaitPasTomberLAppelant(): void
    {
        $piege = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('du code métier, dans le profileur');
            }
        };

        self::assertNull(RecordedDetails::storable(['payload' => $piege]));
    }

    /**
     * Au-delà de 512 niveaux, `json_decode` rend `null` là où l'encodage avait produit du texte.
     * La valeur disparaît — c'est assumé — mais l'appelant doit pouvoir ranger le résultat dans
     * une propriété typée sans lever, d'où l'application clé par clé côté collecteur.
     */
    public function testUneImbricationPlusProfondeQueJsonNeLeTientRendNull(): void
    {
        $profond = 'fond';
        for ($i = 0; $i < 600; ++$i) {
            $profond = [$profond];
        }

        self::assertNull(RecordedDetails::storable($profond));
    }

    /**
     * Les bornes de la frise se déclarent `float`. Sans `JSON_PRESERVE_ZERO_FRACTION`, une durée
     * de trois secondes tout rondes revient en `int` et le type déclaré ment.
     */
    public function testUnFlottantDeValeurEntiereResteUnFlottant(): void
    {
        $storable = RecordedDetails::storable(['spanSec' => 3.0, 'tMin' => 0.0]);

        self::assertIsArray($storable);
        self::assertIsFloat($storable['spanSec']);
        self::assertIsFloat($storable['tMin']);
    }

    #[DataProvider('chargesUtilesOrdinaires')]
    public function testCeQuiEtaitLisibleLeResteALIdentique(mixed $valeur): void
    {
        self::assertSame($valeur, RecordedDetails::storable($valeur));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function chargesUtilesOrdinaires(): iterable
    {
        yield 'chaîne' => ['bonjour'];
        yield 'entier' => [42];
        yield 'flottant' => [1.5];
        yield 'booléen' => [true];
        yield 'null' => [null];
        yield 'liste' => [[1, 2, 3]];
        yield 'tableau associatif' => [['a' => 1, 'b' => ['c' => 'd']]];
    }

    /**
     * Une référence récursive, elle, survit : `JSON_PARTIAL_OUTPUT_ON_ERROR` la coupe et rend le
     * reste. Le cas est ici pour qu'on cesse de le croire cassé.
     */
    public function testUneReferenceRecursiveEstTronqueeEtNonPerdue(): void
    {
        $objet = new \stdClass();
        $objet->nom = 'boucle';
        $objet->soi = $objet;

        $storable = RecordedDetails::storable(['payload' => $objet]);

        self::assertIsArray($storable);
        self::assertSame('boucle', $storable['payload']['nom']);
    }
}
