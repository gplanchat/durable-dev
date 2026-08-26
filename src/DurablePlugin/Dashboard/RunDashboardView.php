<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Dashboard;

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
     *   backend: array{available: bool, message: string},
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

        // Un filtre venu d'une URL est une chaîne quelconque : l'ignorer vaut mieux que refuser une
        // page à quelqu'un qui a mal recopié un lien.
        $filter = WorkflowRunStatus::tryFrom($status);
        $page = $this->catalog->listRuns($filter, $cursor, self::PAGE_SIZE);

        $selected = self::pick($page->runs, $selectedRunId);

        return [
            'backend' => ['available' => true, 'message' => 'Connected to the configured durable backend.'],
            'runs' => array_map(self::describe(...), $page->runs),
            'kpis' => self::countBy($page->runs),
            'pagination' => [
                'cursor' => $cursor,
                'nextCursor' => $page->nextCursor,
                'hasNext' => null !== $page->nextCursor,
            ],
            'status' => $status,
            'selectedRun' => null === $selected ? null : self::describe($selected) + [
                'lanes' => self::lanes($this->catalog->readHistory($selected)),
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
     * @param list<WorkflowRunEvent> $history
     *
     * @return list<array{kind: string, events: list<array{sequence: int, recordedAt: \DateTimeImmutable, label: string}>}>
     */
    private static function lanes(array $history): array
    {
        $lanes = [];
        foreach ($history as $event) {
            $lanes[$event->kind->value][] = [
                'sequence' => $event->sequence,
                'recordedAt' => $event->recordedAt,
                'label' => $event->label,
            ];
        }

        // Aucune voie vide : une voie que le backend n'alimente jamais ne doit pas apparaître, sous
        // peine de faire passer une notion absente pour une exécution qui n'en a pas eu.
        return array_map(
            static fn(string $kind, array $events): array => ['kind' => $kind, 'events' => $events],
            array_keys($lanes),
            $lanes,
        );
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
