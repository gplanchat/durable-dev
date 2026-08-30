<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Ui\DataProvider;

use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Magento\Framework\Api\Filter;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * La source de la grille standard, pour une donnée qui n'est pas une collection SQL.
 *
 * `AbstractDataProvider` est l'échappatoire documentée : il implémente les quinze méthodes du
 * contrat au-dessus d'une collection, et trois d'entre elles se redéfinissent quand il n'y en a
 * pas. C'est ce qui permet d'avoir le châssis d'admin — colonnes, tri, pagination, signets, export
 * — sans inventer une table dont l'état ne serait qu'une copie en retard de la grappe.
 *
 * ⚠ **La pagination est le point de friction, et il est borné plutôt que caché.** La grille pagine
 * par décalage (`setLimit($offset, $size)`) ; la grappe pagine par **curseur de continuation**. Les
 * deux ne se traduisent pas l'un dans l'autre sans état. Ce fournisseur lit donc une **fenêtre**
 * bornée et pagine dedans.
 *
 * La taille de cette fenêtre vit sur {@see RuntimeFactory::OBSERVATION_WINDOW}, et pas ici : l'écran
 * de détail lit la même, et deux littéraux distincts rendaient possible d'être listé ici et
 * introuvable là. Elle est **dite à l'exploitant** par la bannière au-dessus de la grille — une
 * fenêtre bornée qui ne s'annonce pas se découvre par une exécution qui manque.
 */
/*
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui l'étend.
 */
class ProcessListing extends AbstractDataProvider
{
    /**
     * Le rendu d'un fait que **cette exécution** n'a pas, dans une grille à colonnes fixes. Le même
     * que celui de l'écran de détail, et c'est tout l'intérêt de le nommer.
     */
    private const ABSENT = '—';

    /** @var array<string, list<string>|string> */
    private array $filters = [];

    private int $offset = 0;

    private int $size = 20;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        private readonly RuntimeFactory $runtimeFactory,
        array $meta = [],
        array $data = [],
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        $runs = $this->runtimeFactory->catalog()->listRuns(limit: RuntimeFactory::OBSERVATION_WINDOW)->runs;

        $runs = $this->applyFilters($runs);

        $total = \count($runs);
        $window = \array_slice($runs, $this->offset, $this->size);

        return [
            'totalRecords' => $total,
            'items' => array_map(static fn(WorkflowRunDescription $run): array => [
                'run_id' => $run->runId,
                'workflow_name' => $run->workflowName,
                'status' => $run->status->value,
                // ⚠ Un tiret cadratin, pas une chaîne vide. Une exécution en cours n'a pas de date
                // de fin, et la colonne existe pour toutes les autres : une case vide se lit comme
                // un rendu qui a échoué, là où le tiret dit « rien ici ». C'est l'inverse du fait
                // dont le backend n'a **pas la notion** — celui-là n'a pas de colonne du tout.
                'started_at' => $run->startedAt?->format('Y-m-d H:i:s') ?? self::ABSENT,
                'ended_at' => $run->endedAt?->format('Y-m-d H:i:s') ?? self::ABSENT,
            ], $window),
        ];
    }

    /**
     * Le filtre porte sur ce que la fenêtre contient, pas sur la grappe : la visibilité de Temporal
     * a sa propre langue de requête, et la traduire depuis les filtres de la grille serait une
     * surface à part entière. Dire lequel des deux on filtre vaut mieux que laisser croire.
     */
    public function addFilter(Filter $filter): void
    {
        // ⚠ `Filter::getValue()` est annoté `@return string` en amont, et c'est faux : le filtre
        // d'état est un `ui-select`, qui rend un **tableau** dès que l'exploitant coche plus d'une
        // case, et une chaîne quand il n'en coche qu'une. Les deux formes ont été mesurées ici.
        // L'annotation dit ce qui arrive vraiment, plutôt que de faire taire l'analyse.
        /** @var mixed $value */
        $value = $filter->getValue();
        $this->filters[$filter->getField()] = \is_array($value)
            ? array_values(array_map('strval', $value))
            : (string) $value;
    }

    /**
     * @param list<WorkflowRunDescription> $runs
     *
     * @return list<WorkflowRunDescription>
     */
    private function applyFilters(array $runs): array
    {
        foreach ($this->filters as $field => $value) {
            $runs = match ($field) {
                'workflow_name' => array_values(array_filter(
                    $runs,
                    static fn(WorkflowRunDescription $run): bool => str_contains(
                        mb_strtolower($run->workflowName),
                        mb_strtolower((string) $value),
                    ),
                )),
                'run_id' => array_values(array_filter(
                    $runs,
                    static fn(WorkflowRunDescription $run): bool => str_contains($run->runId, (string) $value),
                )),
                'status' => array_values(array_filter(
                    $runs,
                    static fn(WorkflowRunDescription $run): bool => \in_array(
                        $run->status->value,
                        (array) $value,
                        true,
                    ),
                )),
                default => $runs,
            };
        }

        return $runs;
    }

    public function addOrder($field, $direction): void
    {
        // La grappe rend déjà les exécutions les plus récentes en tête, et le catalogue le
        // garantit en retriant. Un tri par colonne demanderait de trier la fenêtre, ce qui
        // mentirait dès que la fenêtre est plus petite que le total.
    }

    public function setLimit($offset, $size): void
    {
        $this->offset = max(0, (int) $offset);
        $this->size = (int) $size > 0 ? (int) $size : 20;
    }
}
