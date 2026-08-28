<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Block\Adminhtml;

use Gplanchat\Durable\Observation\ReadableDuration;
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
     * La frise : une ligne par **action**, placée dans le temps.
     *
     * Une activité planifiée, démarrée puis terminée est une action et trois événements. Ranger par
     * nature — « les activités », « les signaux » — obligeait l'exploitant à recoller trois repères
     * de l'œil pour savoir combien de temps *celle-là* avait duré. Une ligne par action répond à la
     * question directement : la barre est la durée.
     *
     * L'échelle va du premier au dernier événement enregistré, pas du début à la fin de
     * l'exécution : une exécution en cours n'a pas de fin, et une frise qui s'arrête au dernier
     * fait connu ne prétend rien savoir de plus.
     *
     * ⚠ **La barre est découpée entre événements consécutifs**, et ce n'est pas décoratif : dès que
     * l'exécution elle-même occupe une ligne, sa barre couvre toute la durée du run, et le seul
     * fait intéressant — les vingt-deux secondes passées à attendre un worker entre deux de ses
     * événements — disparaîtrait dans une barre qui dit « le run a duré le temps du run ». Chaque
     * segment porte donc son intervalle : survoler la longue portion nomme l'attente.
     *
     * @return array{span: string, actions: list<array{kind: string, label: string, duration: string, segments: list<array{from: float, width: float, title: string}>, marks: list<array{at: float, title: string}>}>}|null
     */
    public function getTimeline(): ?array
    {
        $events = $this->getEvents();
        if ($events === []) {
            return null;
        }

        $moments = [];
        $grouped = [];
        foreach ($events as $event) {
            $moments[$event->sequence] = (float) $event->recordedAt->format('U.u');
            // Un événement sans action est à lui seul la sienne : sa séquence suffit à le
            // distinguer, et il occupe sa ligne comme n'importe quelle autre action.
            $grouped[$event->actionKey ?? ('#' . $event->sequence)][] = $event;
        }

        $first = min($moments);
        $span = max($moments) - $first;

        $actions = [];
        foreach ($grouped as $group) {
            $opening = $group[0];
            $closing = $group[\count($group) - 1];
            $from = $moments[$opening->sequence];
            $to = $moments[$closing->sequence];

            $actions[] = [
                'kind' => $opening->kind->value,
                // Le nom de l'action est celui de l'événement qui l'ouvre : c'est la planification
                // qui connaît le nom de l'activité, ses suites ne portent qu'un numéro.
                'label' => $opening->label,
                'duration' => ReadableDuration::of($to - $from),
                'segments' => $this->segments($group, $moments, $first, $span),
                'marks' => array_map(
                    fn(WorkflowRunEvent $event): array => [
                        'at' => $this->scale($moments[$event->sequence] - $first, $span),
                        'title' => \sprintf(
                            '#%d · %s · %s',
                            $event->sequence,
                            $event->recordedAt->format('H:i:s.v'),
                            $event->label,
                        ),
                    ],
                    $group,
                ),
            ];
        }

        return ['span' => ReadableDuration::of($span), 'actions' => $actions];
    }

    /**
     * Un segment par intervalle entre deux événements consécutifs de l'action.
     *
     * Une action d'un seul événement n'a aucun intervalle, donc aucun segment : un repère seul dit
     * déjà tout ce qu'il y a à dire d'un instant.
     *
     * @param list<WorkflowRunEvent>  $group
     * @param array<int, float>       $moments
     *
     * @return list<array{from: float, width: float, title: string}>
     */
    private function segments(array $group, array $moments, float $first, float $span): array
    {
        $segments = [];
        for ($index = 1, $count = \count($group); $index < $count; ++$index) {
            $opening = $group[$index - 1];
            $closing = $group[$index];
            $from = $moments[$opening->sequence];
            $to = $moments[$closing->sequence];

            $segments[] = [
                'from' => $this->scale($from - $first, $span),
                'width' => $this->scale($to - $from, $span),
                'title' => \sprintf(
                    '%s · #%d → #%d · %s → %s',
                    ReadableDuration::of($to - $from),
                    $opening->sequence,
                    $closing->sequence,
                    $opening->label,
                    $closing->label,
                ),
            ];
        }

        return $segments;
    }

    /**
     * Sans durée — une seule action, ou tout dans la même microseconde — tout se pose à gauche.
     * Étaler par rang ferait passer un ordre pour une durée.
     */
    private function scale(float $seconds, float $span): float
    {
        return $span > 0.0 ? $seconds / $span * 100.0 : 0.0;
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
