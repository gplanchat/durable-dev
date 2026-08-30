<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\RecordedDetails;
use PHPUnit\Framework\TestCase;

/**
 * Ce qu'un backend a enregistré avec un événement, mis en forme **une fois**.
 *
 * Le contenu est le vocabulaire du backend et n'est pas normalisé : un journal maison peut donc
 * tenir une charge utile qui ne survit pas au rendu. Magento le savait — son bloc tolérait la
 * sortie partielle et retombait sur une ligne simple — et Sylius, non : son gabarit passait la
 * charge à `json_encode` sans tolérance, obtenait `false`, et rendait un **dépliant vide**. Soit
 * précisément l'écran qu'un exploitant ouvre en dernier recours, et qui ne s'ouvre sur rien.
 */
final class WhatAnEventCarriesIsRenderedOnceTest extends TestCase
{
    public function testNothingRecordedHasNothingToUnfold(): void
    {
        self::assertNull(RecordedDetails::of([]));
    }

    public function testWhatWasRecordedComesBackReadable(): void
    {
        $rendered = RecordedDetails::of(['payload' => ['customerId' => 'cus-42']]);

        self::assertIsString($rendered);
        self::assertStringContainsString('cus-42', $rendered);
        self::assertStringContainsString("\n", $rendered, 'un exploitant lit une charge utile, il ne la déchiffre pas');
    }

    public function testABadlyEncodedValueDoesNotTakeTheWholeLineDownWithIt(): void
    {
        // Le cas atteignable : une chaîne d'octets qui n'est pas de l'UTF-8 valide. Sans tolérance,
        // `json_encode` rend `false` — et le reste de la charge utile, parfaitement lisible,
        // disparaissait avec l'octet fautif.
        $rendered = RecordedDetails::of(['blob' => "\xB1\x31", 'orderId' => 'ORD-7']);

        self::assertIsString($rendered);
        self::assertStringContainsString('ORD-7', $rendered);
    }

    public function testAValueOfATypeJsonCannotHoldIsShownAsAbsentAndNotAsAnError(): void
    {
        // Une ressource, un objet fermé : la sortie partielle les rend `null`, et le reste de la
        // charge utile arrive entier. C'est mieux que la ligne simple que le contrat autorise —
        // l'exploitant voit ce qui a été enregistré **et** qu'un champ n'a pas pu l'être.
        $handle = fopen('php://memory', 'r');
        self::assertIsResource($handle);

        $rendered = RecordedDetails::of(['handle' => $handle, 'orderId' => 'ORD-7']);
        fclose($handle);

        self::assertIsString($rendered);
        self::assertStringContainsString('ORD-7', $rendered);
        self::assertStringContainsString('null', $rendered);
    }

}
