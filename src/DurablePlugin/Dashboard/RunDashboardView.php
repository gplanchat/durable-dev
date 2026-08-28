<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Dashboard;

use Gplanchat\Durable\Observation\ReadableDuration;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;

/**
 * Ce que la page a besoin de savoir, tiré du port et de rien d'autre.
 *
 * Le catalogue est nullable, et c'est le cas normal : le conteneur n'en enregistre aucun quand
 * aucun backend n'est lisible. La page dit alors qu'aucun backend n'est configuré — **sans nommer
 * Temporal**, qui peut n'avoir jamais été de la partie sur cette application.
 *
 * Un fait que le backend n'a pas est **absent** du modèle, pas rendu en chaîne vide : une colonne
 * « file de tâches » vide apprend à l'exploitant que l'exécution n'a pas de file, alors que c'est le
 * backend qui n'a pas la notion. Une clé absente ne raconte rien de faux.
 */
final class RunDashboardView
{
    public const PAGE_SIZE = 20;

    public function __construct(
        private readonly ?WorkflowRunCatalogInterface $catalog,
    ) {}

    /**
     * @return array{
     *   backend: array<string, mixed>,
     *   runs: list<array<string, mixed>>,
     *   kpis: array<string, int>,
     *   pagination: array{cursor: string|null, nextCursor: string|null, hasNext: bool},
     *   status: string,
     *   selectedRun: array<string, mixed>|null
     * }
     */
    public function build(string $status = 'all', ?string $cursor = null, ?string $selectedRunId = null): array
    {
        if (null === $this->catalog) {
            return [
                'backend' => [
                    'available' => false,
                    'message' => 'No readable durable backend is configured for this application.',
                ],
                'runs' => [],
                'kpis' => self::countBy([]),
                'pagination' => ['cursor' => $cursor, 'nextCursor' => null, 'hasNext' => false],
                'status' => $status,
                'selectedRun' => null,
            ];
        }

        // « Un catalogue est enregistré » et « le backend répond » sont deux questions distinctes.
        // Sans cette seconde, une base tombée donnerait une page vide et sereine — la pire des deux
        // erreurs possibles, puisque l'exploitant en conclut qu'il n'y a rien à voir.
        $health = $this->catalog->checkHealth();
        if (!$health->reachable) {
            return [
                'backend' => [
                    'available' => false,
                    'message' => $health->message,
                    'name' => $health->backend,
                    'checkedAt' => $health->checkedAt,
                ],
                'runs' => [],
                'kpis' => self::countBy([]),
                'pagination' => ['cursor' => $cursor, 'nextCursor' => null, 'hasNext' => false],
                'status' => $status,
                'selectedRun' => null,
            ];
        }

        // Un filtre venu d'une URL est une chaîne quelconque : l'ignorer vaut mieux que refuser une
        // page à quelqu'un qui a mal recopié un lien.
        $filter = WorkflowRunStatus::tryFrom($status);
        $page = $this->catalog->listRuns($filter, $cursor, self::PAGE_SIZE);

        $selected = self::pick($page->runs, $selectedRunId);

        return [
            'backend' => [
                'available' => true,
                'message' => $health->message,
                'name' => $health->backend,
                'checkedAt' => $health->checkedAt,
            ],
            'runs' => array_map(self::describe(...), $page->runs),
            'kpis' => self::countBy($page->runs),
            'pagination' => [
                'cursor' => $cursor,
                'nextCursor' => $page->nextCursor,
                'hasNext' => null !== $page->nextCursor,
            ],
            'status' => $status,
            'selectedRun' => null === $selected ? null : self::describe($selected) + [
                'actions' => self::actions($this->catalog->readHistory($selected)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(WorkflowRunDescription $run): array
    {
        $described = [
            'runId' => $run->runId,
            'workflowName' => $run->workflowName,
            'status' => $run->status->value,
        ];

        // Chaque fait n'entre que s'il existe. C'est la règle de tout ce modèle.
        if (null !== $run->startedAt) {
            $described['startedAt'] = $run->startedAt;
        }
        if (null !== $run->endedAt) {
            $described['endedAt'] = $run->endedAt;
        }
        if (null !== $run->groupId) {
            $described['groupId'] = $run->groupId;
        }

        return $described;
    }

    /**
     * L'historique regroupé par **action**, pas par nature.
     *
     * Une activité planifiée, démarrée puis terminée est une action et trois événements ; les
     * événements de l'exécution elle-même sont une action, la première. Ranger par nature obligeait
     * l'exploitant à recoller trois lignes de l'œil pour savoir combien de temps celle-là avait
     * duré — et noyait les quatre actions intéressantes d'une commande sous la plomberie du moteur.
     *
     * `kind` reste porté par l'action : la couleur de sa bordure vient de là, et une action a la
     * nature de l'événement qui l'ouvre.
     *
     * @param list<WorkflowRunEvent> $history
     *
     * @return list<array{kind: string, label: string, took: string, events: list<array{sequence: int, recordedAt: \DateTimeImmutable, label: string, details?: array<string, mixed>}>}>
     */
    private static function actions(array $history): array
    {
        $grouped = [];
        foreach ($history as $event) {
            $described = [
                'sequence' => $event->sequence,
                'recordedAt' => $event->recordedAt,
                'label' => $event->label,
            ];

            // Même règle que partout dans ce modèle : un fait n'entre que s'il existe. Le gabarit
            // n'a donc pas à distinguer « rien enregistré » de « tableau vide », et un événement
            // sans contenu garde une ligne simple plutôt qu'un dépliant qui s'ouvre sur du vide.
            if ([] !== $event->details) {
                $described['details'] = $event->details;
            }

            // Un événement sans action est à lui seul la sienne : sa séquence suffit à le
            // distinguer, et il occupe son bloc comme n'importe quelle autre action.
            $grouped[$event->actionKey ?? ('#' . $event->sequence)][] = ['event' => $event, 'described' => $described];
        }

        $actions = [];
        foreach ($grouped as $group) {
            $opening = $group[0]['event'];
            $closing = $group[\count($group) - 1]['event'];

            $actions[] = [
                'kind' => $opening->kind->value,
                // Le nom de l'action est celui de l'événement qui l'ouvre : c'est la planification
                // qui connaît le nom de l'activité, ses suites ne portent qu'un numéro.
                'label' => $opening->label,
                'took' => ReadableDuration::of(
                    (float) $closing->recordedAt->format('U.u') - (float) $opening->recordedAt->format('U.u'),
                ),
                'events' => array_column($group, 'described'),
            ];
        }

        return $actions;
    }

    /**
     * @param list<WorkflowRunDescription> $runs
     */
    private static function pick(array $runs, ?string $selectedRunId): ?WorkflowRunDescription
    {
        foreach ($runs as $run) {
            if ($run->runId === $selectedRunId) {
                return $run;
            }
        }

        return $runs[0] ?? null;
    }

    /**
     * Un compteur par issue, toutes les issues.
     *
     * Énumérer les cas plutôt que les écrire à la main évite le trou qu'une liste figée creuse
     * fatalement : `continued_as_new` comptait dans le total et dans aucun seau, si bien qu'une
     * application faite de workflows longs affichait des compteurs qui ne s'additionnaient pas.
     *
     * @param list<WorkflowRunDescription> $runs
     *
     * @return array<string, int>
     */
    private static function countBy(array $runs): array
    {
        $kpis = ['total' => \count($runs)];
        foreach (WorkflowRunStatus::cases() as $case) {
            $kpis[$case->value] = 0;
        }

        foreach ($runs as $run) {
            ++$kpis[$run->status->value];
        }

        return $kpis;
    }
}
