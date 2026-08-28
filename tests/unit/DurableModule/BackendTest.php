<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Magento;

use Gplanchat\Durable\Magento\Config\Backend;
use Gplanchat\Durable\Magento\Config\UnsupportedBackendException;
use PHPUnit\Framework\TestCase;

/**
 * Le choix de backend, et les deux qu'il faut refuser en les nommant.
 *
 * Le site l'encode déjà — `ALLOWED.magento` vaut `['memory', 'temporal']` — parce que Magento ne
 * livre aucun des deux types de connexion auxquels les ponts SQL se lient. La question n'est donc
 * pas *si* on refuse, mais *quand* : au moment où un processus assemble le moteur, ou plus tard,
 * quand un workflow attend un journal que personne n'écrit.
 *
 * Ce test fige le vocabulaire en même temps que le refus. `memory` est celui du sélecteur, pas le
 * `in_memory` du bundle Symfony : là-bas c'est le type d'un magasin d'événements, ici la famille
 * de backend que la page d'accueil nomme et que la 6.2 devra retrouver.
 */
final class BackendTest extends TestCase
{
    public function testTheTwoBackendsMagentoReachesAreAccepted(): void
    {
        self::assertSame(Backend::Memory, Backend::fromConfiguredName('memory'));
        self::assertSame(Backend::Temporal, Backend::fromConfiguredName('temporal'));
    }

    public function testAnAbsentChoiceFallsBackToTheOneThisHostAlwaysHas(): void
    {
        self::assertSame(Backend::Memory, Backend::fromConfiguredName(null));
    }

    /**
     * Le refus dit lequel, et dit pourquoi : sans la raison, l'exploitant relit sa configuration
     * en cherchant une faute de frappe.
     */
    public function testASqlBackendIsRefusedByName(): void
    {
        foreach (['dbal', 'illuminate'] as $refused) {
            try {
                Backend::fromConfiguredName($refused);
                self::fail(\sprintf('The %s backend should have been refused.', $refused));
            } catch (UnsupportedBackendException $exception) {
                self::assertStringContainsString($refused, $exception->getMessage());
                self::assertStringContainsString('memory', $exception->getMessage());
                self::assertStringContainsString('temporal', $exception->getMessage());
            }
        }
    }

    public function testAnUnknownNameIsRefusedWithTheListOfWhatExists(): void
    {
        $this->expectException(UnsupportedBackendException::class);
        $this->expectExceptionMessageMatches('/postgres/');

        Backend::fromConfiguredName('postgres');
    }
}
