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
 * ponytail: fenêtre de 200 exécutions. Au-delà, la grille dit la vérité sur ce qu'elle montre mais
 * ne montre pas tout. Le jour où ça gêne, la sortie est de mémoriser les curseurs par page dans la
 * session de l'administrateur — pas d'agrandir la fenêtre.
 */
/*
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui l'étend.
 */
class ProcessListing extends AbstractDataProvider
{
    private const WINDOW = 200;

    /** @var array<string, string> */
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
        $runs = $this->runtimeFactory->catalog()->listRuns(limit: self::WINDOW)->runs;

        if (isset($this->filters['workflowName'])) {
            $needle = mb_strtolower($this->filters['workflowName']);
            $runs = array_values(array_filter(
                $runs,
                static fn(WorkflowRunDescription $run): bool => str_contains(mb_strtolower($run->workflowName), $needle),
            ));
        }

        $total = \count($runs);
        $window = \array_slice($runs, $this->offset, $this->size);

        return [
            'totalRecords' => $total,
            'items' => array_map(static fn(WorkflowRunDescription $run): array => [
                'run_id' => $run->runId,
                'workflow_name' => $run->workflowName,
                'status' => $run->status->value,
                'started_at' => $run->startedAt?->format('Y-m-d H:i:s') ?? '',
                'ended_at' => $run->endedAt?->format('Y-m-d H:i:s') ?? '',
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
        $this->filters[$filter->getField()] = (string) $filter->getValue();
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
