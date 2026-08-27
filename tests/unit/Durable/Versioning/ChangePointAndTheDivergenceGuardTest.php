<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Versioning;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\VersionMarked;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Versioning\ChangePoint;
use PHPUnit\Framework\TestCase;

/**
 * Le versioning est l'exception sanctionnée à la garde de divergence (DUR042) — et une exception
 * qui désarmerait la règle vaudrait moins que pas d'exception du tout.
 *
 * Les deux mécanismes se rencontrent au même endroit : la garde compare l'identité au slot, et un
 * point de changement fait justement varier ce que le code demande aux slots suivants. Ce fichier
 * tient les trois faits qui rendent la cohabitation sûre.
 */
final class ChangePointAndTheDivergenceGuardTest extends TestCase
{
    private const EXECUTION = 'exec-guard-version';

    public function testTheGuardDoesNotFireOnABranchTheVersionDecided(): void
    {
        // L'exécution est sur la version 1 : son journal porte `discountedCharge` au slot 0,
        // et c'est bien ce que la branche v1 du code demande. Aucune divergence.
        $store = new InMemoryEventStore();
        $store->append(new VersionMarked(self::EXECUTION, 'ajout-remise', 1));
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'discountedCharge', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 90));

        $context = $this->context($store);
        $version = $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(1, $version);

        // La branche que cette version commande. La garde ne doit pas broncher.
        $awaitable = 1 === $version
            ? $context->activity('discountedCharge', [])
            : $context->activity('plainCharge', []);

        self::assertTrue($awaitable->isSettled(), 'le slot se résout : le code a suivi sa version');
    }

    public function testAnOldRunTakesTheOtherBranchWithoutDivergingEither(): void
    {
        // La même exécution, mais partie avant le point : elle a `plainCharge` au slot 0, et la
        // branche par défaut demande exactement ça. Les deux branches sont donc légitimes —
        // chacune pour l'exécution qui la concerne.
        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'plainCharge', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 100));
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-2', 'shipOrder', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-2', 'shipped'));

        $context = $this->context($store);
        $version = $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(ChangePoint::DEFAULT_VERSION, $version);

        $awaitable = ChangePoint::DEFAULT_VERSION === $version
            ? $context->activity('plainCharge', [])
            : $context->activity('discountedCharge', []);

        self::assertTrue($awaitable->isSettled());
    }

    public function testVersioningOnePointDoesNotDisarmTheGuardElsewhere(): void
    {
        // Le point de changement est respecté, et un AUTRE slot est changé sans le déclarer.
        // C'est là que se joue la valeur de l'exception : elle ne couvre que ce qu'elle nomme.
        $store = new InMemoryEventStore();
        $store->append(new VersionMarked(self::EXECUTION, 'ajout-remise', 1));
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'discountedCharge', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 90));
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-2', 'shipOrder', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-2', 'shipped'));

        $context = $this->context($store);
        $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);
        $context->activity('discountedCharge', []);

        $this->expectException(WorkflowTaskFailure::class);
        $context->activity('sendInvoice', []); // le journal tient « shipOrder »
    }

    public function testTheVersionIsDecidedBeforeTheSlotsItCommands(): void
    {
        // L'ordre est le fond du sujet : si la version était résolue APRÈS le slot qu'elle
        // décide, l'exécution se serait déjà servie d'une réponse qu'elle n'avait pas.
        // Le marqueur doit donc précéder, dans le journal, l'activité qu'il commande.
        $store = new InMemoryEventStore();
        $context = $this->context($store);

        $version = $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);
        $context->activity(1 === $version ? 'discountedCharge' : 'plainCharge', []);

        $kinds = array_map(
            static fn(object $e): string => $e::class,
            iterator_to_array($store->readStream(self::EXECUTION)),
        );
        $marker = array_search(VersionMarked::class, $kinds, true);
        $scheduled = array_search(ActivityScheduled::class, $kinds, true);

        self::assertIsInt($marker, 'le marqueur est écrit');
        self::assertIsInt($scheduled, "l'activité est planifiée");
        self::assertLessThan(
            $scheduled,
            $marker,
            "la version est décidée et enregistrée AVANT le slot qu'elle commande",
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
