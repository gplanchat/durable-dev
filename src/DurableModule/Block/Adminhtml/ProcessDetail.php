<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Block\Adminhtml;

use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Ce qu'une exécution a fait, lu dans son journal.
 *
 * Rien de spécifique à Magento ici non plus : `readHistory()` est le même port que le tableau de
 * bord Sylius interroge, et il rend les mêmes `WorkflowRunEvent`. Une observation, une surface par
 * hôte.
 */
/*
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui l'étend.
 */
class ProcessDetail extends Template
{
    private ?WorkflowRunDescription $run = null;

    private bool $looked = false;

    /** @var list<WorkflowRunEvent>|null */
    private ?array $events = null;

    public function __construct(
        Context $context,
        private readonly RuntimeFactory $runtimeFactory,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getRunId(): string
    {
        return (string) $this->getRequest()->getParam('run_id');
    }

    /**
     * L'exécution demandée, ou `null` si la grappe ne la connaît pas — un identifiant collé à la
     * main dans la barre d'adresse, ou une exécution que la rétention a effacée.
     */
    public function getRun(): ?WorkflowRunDescription
    {
        if (!$this->looked) {
            $this->looked = true;
            $wanted = $this->getRunId();

            foreach ($this->runtimeFactory->catalog()->listRuns(limit: 200)->runs as $candidate) {
                if ($candidate->runId === $wanted) {
                    $this->run = $candidate;

                    break;
                }
            }
        }

        return $this->run;
    }

    /**
     * @return list<WorkflowRunEvent>
     */
    public function getEvents(): array
    {
        // Le gabarit lit l'historique deux fois — la frise puis le tableau. Sans mémoire, c'est un
        // second aller-retour vers la grappe pour la même réponse.
        if ($this->events === null) {
            $run = $this->getRun();
            $this->events = $run === null ? [] : $this->runtimeFactory->catalog()->readHistory($run);
        }

        return $this->events;
    }

    /**
     * La frise : les événements placés dans le temps, une voie par nature.
     *
     * Le tableau répond « dans quel ordre », la frise répond « quand, et pendant combien de temps
     * il ne s'est rien passé ». C'est la deuxième qui montre l'attente — le trou de vingt secondes
     * entre la planification d'une activité et son résultat est un fait qu'une liste de lignes
     * régulièrement espacées cache activement.
     *
     * L'échelle va du premier au dernier événement enregistré, pas du début à la fin de
     * l'exécution : une exécution en cours n'a pas de fin, et une frise qui s'arrête au dernier
     * fait connu ne prétend rien savoir de plus.
     *
     * ponytail: repères ponctuels, pas de barres. Une barre demanderait de relier la planification
     * d'une activité à sa complétion, or `WorkflowRunEvent` ne porte pas de quoi les corréler — le
     * jour où ça manquera, c'est le port qu'il faudra ouvrir, pas ce gabarit.
     *
     * @return array{span: string, lanes: list<array{kind: string, marks: list<array{at: float, title: string}>}>}|null
     */
    public function getTimeline(): ?array
    {
        $events = $this->getEvents();
        if ($events === []) {
            return null;
        }

        $moments = array_map(
            static fn(WorkflowRunEvent $event): float => (float) $event->recordedAt->format('U.u'),
            $events,
        );
        $first = min($moments);
        $span = max($moments) - $first;

        $lanes = [];
        foreach ($events as $index => $event) {
            // Sans durée — un seul événement, ou tous dans la même microseconde — tout se pose à
            // gauche. Étaler par rang ferait passer un ordre pour une durée.
            $lanes[$event->kind->value][] = [
                'at' => $span > 0.0 ? ($moments[$index] - $first) / $span * 100.0 : 0.0,
                'title' => \sprintf(
                    '#%d · %s · %s',
                    $event->sequence,
                    $event->recordedAt->format('H:i:s.v'),
                    $event->label,
                ),
            ];
        }

        return [
            'span' => $this->formatSpan($span),
            // Une voie que le backend n'alimente jamais n'apparaît pas : la faire figurer vide
            // ferait passer une notion absente pour une exécution qui n'en a pas eu.
            'lanes' => array_map(
                static fn(string $kind, array $marks): array => ['kind' => $kind, 'marks' => $marks],
                array_keys($lanes),
                $lanes,
            ),
        ];
    }

    private function formatSpan(float $seconds): string
    {
        return match (true) {
            $seconds < 1.0 => \sprintf('%d ms', (int) round($seconds * 1000)),
            $seconds < 90.0 => \sprintf('%.1f s', $seconds),
            default => \sprintf('%d min %02d s', (int) ($seconds / 60), (int) fmod($seconds, 60)),
        };
    }

    /**
     * Le contenu d'un événement, mis en forme pour être lu, ou `null` quand il n'y a rien à
     * déplier — un événement sans détail garde une ligne simple plutôt qu'un dépliant vide.
     */
    public function formatDetails(WorkflowRunEvent $event): ?string
    {
        if ($event->details === []) {
            return null;
        }

        $rendered = json_encode(
            $event->details,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        // `JSON_PARTIAL_OUTPUT_ON_ERROR` couvre la ressource ou la chaîne mal encodée qu'un journal
        // maison peut contenir ; `false` reste possible, et une ligne sans dépliant vaut mieux
        // qu'un écran de diagnostic qui tombe sur l'événement qu'on était venu regarder.
        return $rendered === false ? null : $rendered;
    }

    public function formatMoment(?\DateTimeImmutable $moment): string
    {
        return $moment === null ? '—' : $moment->format('Y-m-d H:i:s');
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('durable/process/history');
    }
}
