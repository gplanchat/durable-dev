<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\WorkflowNamespace;
use PHPUnit\Framework\TestCase;

/**
 * Contrairement à {@see TaskQueue}, une erreur de namespace ne se tait pas : le serveur répond
 * `NOT_FOUND`. L'apport est donc surtout le typage — namespace et file de tâches étaient deux
 * chaînes voisines dans les mêmes constructeurs.
 *
 * Verdicts serveur sondés : seul « non vide » est exigé ; espaces, majuscules, accents,
 * tabulations et plus de 255 caractères sont acceptés. Mais la comparaison est faite octet pour
 * octet — `DURABLE-TEST` et `durable-test ` sont introuvables quand `durable-test` existe.
 */
final class WorkflowNamespaceTest extends TestCase
{
    public function testANameIsCarriedVerbatim(): void
    {
        self::assertSame('durable-test', WorkflowNamespace::named('durable-test')->name());
        self::assertSame('durable-test', (string) WorkflowNamespace::named('durable-test'));
    }

    public function testComparisonIsCaseSensitiveLikeTheServer(): void
    {
        // Sondé : démarrer dans « DURABLE-TEST » quand « durable-test » existe donne NOT_FOUND.
        self::assertFalse(WorkflowNamespace::named('durable-test')->equals(WorkflowNamespace::named('DURABLE-TEST')));
        self::assertTrue(WorkflowNamespace::named('durable-test')->equals(WorkflowNamespace::named('durable-test')));
    }

    public function testEmptyAndBlankAreRejected(): void
    {
        $this->expectExceptionMessageMatches('/cannot be empty/');
        WorkflowNamespace::named('');
    }

    public function testEdgeWhitespaceIsRejected(): void
    {
        // Sondé : « durable-test » (avec espace final) est un autre namespace, donc introuvable.
        $this->expectExceptionMessageMatches('/byte for byte/');

        WorkflowNamespace::named('durable-test ');
    }

    public function testAControlCharacterIsRejected(): void
    {
        $this->expectExceptionMessageMatches('/control character/');

        WorkflowNamespace::named("durable\ttest");
    }

    public function testTheSystemNamespaceIsRecognised(): void
    {
        self::assertTrue(WorkflowNamespace::named('temporal-system')->isSystem());
        self::assertFalse(WorkflowNamespace::named('durable-test')->isSystem());
    }

    public function testANamespaceAndATaskQueueCanNoLongerBeSwapped(): void
    {
        // C'est l'apport principal : les deux étaient des chaînes voisines dans les mêmes
        // constructeurs, et les intervertir ne se voyait qu'à l'exécution, côté serveur.
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore-next-line intentionnel : c'est le point du test */
        new TemporalConnection(target: 'localhost:7233', namespace: TaskQueue::named('durable-activities'));
    }

    public function testChildOptionsRoundTripTheNamespace(): void
    {
        $options = new ChildWorkflowOptions(namespace: WorkflowNamespace::named('other-tenant'));

        self::assertSame('other-tenant', $options->toSchedulingMetadata()['namespace']);
    }

    public function testConnectionAcceptsEitherFormAndValidatesIt(): void
    {
        self::assertSame(
            'durable-test',
            (new TemporalConnection(target: 'localhost:7233', namespace: 'durable-test'))->namespace->name(),
        );

        $this->expectExceptionMessageMatches('/byte for byte/');
        new TemporalConnection(target: 'localhost:7233', namespace: ' durable-test');
    }
}
