<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableModule;

use Gplanchat\Durable\Observation\RunTimeline;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use PHPUnit\Framework\TestCase;

/**
 * Le gabarit de détail, rendu pour de vrai.
 *
 * ⚠ **Rien ne le vérifiait, et rien ne pouvait le vérifier.** PHPStan et Psalm tournent contre les
 * vraies classes de Magento dans la CI, mais aucun des deux n'analyse un `.phtml`. Ce gabarit vient
 * d'être réécrit sur une API d'objets — `$action->durationLabel`, `$row->actionLabel`,
 * `$row->renderedDetails` — là où il lisait des tableaux : une propriété mal nommée n'y casse rien à
 * l'installation et rend un écran vide, sur celui qu'un exploitant est venu regarder.
 *
 * Le bloc, lui, est typé et la CI le voit. C'est le gabarit qui n'avait pas de filet ; il en a un,
 * et il n'a besoin ni de Magento ni d'une base — un double qui répond aux méthodes appelées suffit,
 * et c'est ce qui rend ce test tenable dans la suite ordinaire.
 */
final class TheDetailTemplateRendersARunHistoryTest extends TestCase
{
    public function testTheFriezePlacesTheActionsAndHatchesTheWait(): void
    {
        $page = $this->renderDetail();

        self::assertStringContainsString('durable-frieze', $page);
        // La prise en charge tombe à 10 s sur une portée de 20 s : la barre d'attente occupe la
        // première moitié de la piste. Un étalement par rang l'aurait mise ailleurs.
        self::assertStringContainsString('left: 0.000%; width: 50.000%', $page);
        self::assertStringContainsString('waiting', $page);
        self::assertStringContainsString('waiting to be picked up', $page);
    }

    public function testEveryJournalRowNamesItsActionAndNotItsEvent(): void
    {
        // `ActivityTaskStarted` nomme la classe de l'événement ; l'exploitant cherche `charge`. La
        // colonne porte donc le nom de l'action, et c'est la même chaîne que sur la ligne de frise.
        $page = $this->renderDetail();

        // La colonne « Action » des deux lignes de l'activité, planification et démarrage.
        self::assertSame(2, substr_count($page, '<td>charge</td>'));
        self::assertStringContainsString('ActivityTaskStarted', $page, 'la ligne garde aussi son propre libellé');
        // Et le signal, qui est à lui seul son action, se nomme lui-même plutôt que de laisser un
        // trou dans la colonne.
        self::assertSame(1, substr_count($page, '<td>orderApproved</td>'));
    }

    public function testAnEventWithNothingRecordedHasNoExpander(): void
    {
        // Un dépliant qui s'ouvre sur du vide se rouvre à chaque fois.
        $page = $this->renderDetail();

        self::assertSame(1, substr_count($page, '<details>'));
        self::assertStringContainsString('ORD-7', $page);
    }

    public function testAnUnknownRunSaysSoRatherThanRenderingAnEmptyScreen(): void
    {
        $page = $this->renderDetail(known: false);

        self::assertStringContainsString('No execution named', $page);
        self::assertStringNotContainsString('durable-frieze', $page);
    }

    private function renderDetail(bool $known = true): string
    {
        require_once __DIR__ . '/Fixture/magento-template-globals.php';

        $block = new DetailBlockDouble($known);
        $escaper = new EscaperDouble();

        ob_start();

        try {
            require __DIR__ . '/../../../src/DurableModule/view/adminhtml/templates/process/detail.phtml';
        } finally {
            // `finally` et non la suite du flot : une erreur dans le gabarit laisserait sinon le
            // tampon ouvert, et PHPUnit signale alors un test « risqué » par-dessus l'échec réel —
            // deux messages pour une cause, dont celui qui compte n'est pas le premier.
            $page = ob_get_clean();
        }

        self::assertIsString($page);

        return $page;
    }
}

/**
 * Ce que le gabarit appelle sur son bloc, et rien de plus. Le vrai bloc étend `Template`, qui
 * réclame le conteneur de Magento — absent de cette suite, et il n'a rien à y faire.
 */
final class DetailBlockDouble
{
    private readonly RunTimeline $timeline;

    public function __construct(
        private readonly bool $known = true,
    ) {
        $this->timeline = RunTimeline::of($known ? [
            new WorkflowRunEvent(
                1,
                new \DateTimeImmutable('@1700000000'),
                WorkflowRunEventKind::Activity,
                'charge',
                ['orderId' => 'ORD-7'],
                'activity:act-1',
            ),
            // Prise en charge dix secondes plus tard : les dix premières secondes sont une file.
            new WorkflowRunEvent(
                2,
                new \DateTimeImmutable('@1700000010'),
                WorkflowRunEventKind::Activity,
                'ActivityTaskStarted',
                [],
                'activity:act-1',
                started: true,
            ),
            new WorkflowRunEvent(
                3,
                new \DateTimeImmutable('@1700000020'),
                WorkflowRunEventKind::Signal,
                'orderApproved',
            ),
        ] : []);
    }

    public function getRunId(): string
    {
        return 'run-1';
    }

    public function getRun(): ?WorkflowRunDescription
    {
        return $this->known
            ? new WorkflowRunDescription('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running, new \DateTimeImmutable('@1700000000'))
            : null;
    }

    public function getTimeline(): RunTimeline
    {
        return $this->timeline;
    }

    public function scale(float $seconds): string
    {
        $span = $this->timeline->span;

        return number_format($span > 0.0 ? $seconds / $span * 100.0 : 0.0, 3, '.', '');
    }

    public function formatMoment(?\DateTimeImmutable $moment): string
    {
        return $moment === null ? '—' : $moment->format('Y-m-d H:i:s');
    }

    public function getBackUrl(): string
    {
        return '/admin/durable/process/history';
    }
}

/**
 * Le contrat d'échappement de Magento, réduit à ce que ce gabarit appelle.
 */
final class EscaperDouble
{
    public function escapeHtml(mixed $value): string
    {
        return htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    public function escapeHtmlAttr(mixed $value): string
    {
        return $this->escapeHtml($value);
    }

    public function escapeUrl(string $value): string
    {
        return $this->escapeHtml($value);
    }
}
