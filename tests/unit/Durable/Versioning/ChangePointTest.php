<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Versioning;

use Gplanchat\Durable\Event\VersionMarked;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Versioning\ChangePoint;
use PHPUnit\Framework\TestCase;

/**
 * Un point de changement déclaré : le code dit « ici, mon comportement a changé », et l'exécution
 * apprend lequel des deux la concerne.
 *
 * La convention du fil a été sondée avant d'être encodée (tâches 1.1–1.2) : marqueur « Version »,
 * `change-id` et `version` en json/plain, accepté par un vrai serveur et identique à ce que le SDK
 * Go écrit.
 */
final class ChangePointTest extends TestCase
{
    private const EXECUTION = 'exec-version';

    public function testAFreshRunTakesTheNewestSupportedVersionAndRecordsIt(): void
    {
        $store = new InMemoryEventStore();
        $context = $this->context($store);

        $version = $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(1, $version, 'une exécution neuve prend la version la plus récente');

        $marks = array_values(array_filter(
            iterator_to_array($store->readStream(self::EXECUTION)),
            static fn(object $e): bool => $e instanceof VersionMarked,
        ));
        self::assertCount(1, $marks, 'et le fait est enregistré, une fois');
        self::assertSame('ajout-remise', $marks[0]->changeId());
        self::assertSame(1, $marks[0]->version());
    }

    public function testTheAnswerComesBackFromTheJournalOnReplay(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new VersionMarked(self::EXECUTION, 'ajout-remise', 1));

        // Le code déployé sait faire jusqu'à la version 3 ; l'exécution, elle, est sur la 1.
        $version = $this->context($store)->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 3);

        self::assertSame(1, $version, "l'historique décide, pas le code déployé");
    }

    public function testTheAnswerDoesNotMoveAcrossReplays(): void
    {
        $store = new InMemoryEventStore();

        $first = $this->context($store)->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);
        $second = $this->context($store)->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 5);
        $third = $this->context($store)->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 5);

        self::assertSame(1, $first);
        self::assertSame($first, $second, 'un code plus récent ne déplace pas une exécution en cours');
        self::assertSame($first, $third);
    }

    public function testTheSameRunIsNotMarkedTwice(): void
    {
        $store = new InMemoryEventStore();
        $context = $this->context($store);

        $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);
        $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        $marks = array_filter(
            iterator_to_array($store->readStream(self::EXECUTION)),
            static fn(object $e): bool => $e instanceof VersionMarked,
        );
        self::assertCount(1, $marks, 'le marqueur est écrit à la première rencontre, pas à chaque appel');
    }

    public function testTwoChangePointsAreIndependent(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new VersionMarked(self::EXECUTION, 'ajout-remise', 1));
        $context = $this->context($store);

        self::assertSame(1, $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 2));
        self::assertSame(
            2,
            $context->version('ajout-tva', ChangePoint::DEFAULT_VERSION, 2),
            "une exécution peut être du vieux côté d'un point et du neuf côté d'un autre",
        );
    }

    private function context(InMemoryEventStore $store): ExecutionContext
    {
        return new ExecutionContext(
            self::EXECUTION,
            new EventStoreHistorySource($store, self::EXECUTION),
            new EventStoreCommandBuffer($store, new NoopActivityTransport(), self::EXECUTION),
        );
    }
}
