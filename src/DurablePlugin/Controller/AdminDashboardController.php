<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Controller;

use Gplanchat\Durable\Bundle\Observation\WorkerPresence;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Plugin\Grid\RunGridViews;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

/**
 * The page now does nothing but carry: the filter and the cursor to the catalogue, the view model
 * to the template. Everything that knew how to speak gRPC has moved to the Temporal bridge.
 */
final readonly class AdminDashboardController
{
    public function __construct(
        private readonly Environment $twig,
        /** The run list as a Sylius grid (#383); absent without sylius/grid-bundle. */
        private readonly ?RunGridViews $grid = null,
        /** Who polls each `durable:worker` role; absent where no worker polls a Temporal cluster, which the page says. */
        private readonly ?WorkerPresence $workers = null,
    ) {}

    #[IsGranted('ROLE_ADMINISTRATION_ACCESS')]
    public function index(Request $request, RunDashboard $view): Response
    {
        $this->requireTheSyliusAdmin();
        // The list pages forward only: Temporal cannot page backwards, and the user dropped the
        // previous page (#383, 2026-09-28). A link from before carried the way back in `back`: it
        // lands on the first page of the same list, rather than on a page no link leads back from.
        if ($request->query->has('back')) {
            $first = array_diff_key($request->query->all(), ['cursor' => true, 'back' => true]);

            return new RedirectResponse(
                $request->getBaseUrl() . $request->getPathInfo() . ([] === $first ? '' : '?' . http_build_query($first)),
                Response::HTTP_MOVED_PERMANENTLY,
            );
        }
        ['status' => $status, 'cursor' => $cursor] = self::listPosition($request);

        $grid = $this->grid?->view($request->query->all());
        $model = null === $grid
            ? $view->listing('' === $status ? 'all' : $status, '' === $cursor ? null : $cursor)
            : ['resources' => $grid['resources']] + $grid['page']->model;
        $model['pagination']['isFirstPage'] = '' === $cursor;
        $model['workers'] = $this->workerRows($model['backend'] ?? []);

        return new Response($this->twig->render('@DurablePlugin/admin/dashboard/index.html.twig', $model));
    }

    /**
     * One run, at an address that is its id alone (#264). The list position in the query string is
     * only the way back to the list the run was opened from.
     */
    #[IsGranted('ROLE_ADMINISTRATION_ACCESS')]
    public function show(string $runId, Request $request, RunDashboard $view): Response
    {
        $this->requireTheSyliusAdmin();

        $model = $view->run($runId);
        // Only a backend that answers can say a run does not exist; one that does not is said on
        // the page, not turned into a not-found.
        if (true === $model['backend']['available'] && null === $model['run']) {
            throw new NotFoundHttpException(\sprintf('No run "%s" in the catalog.', $runId));
        }

        return new Response($this->twig->render('@DurablePlugin/admin/dashboard/index.html.twig', [
            'backend' => $model['backend'],
            'selectedRun' => $model['run'],
            'list' => self::listPosition($request),
        ]));
    }

    /**
     * The former single page: `?run=` leads to that run, anything else to the list it showed.
     */
    #[IsGranted('ROLE_ADMINISTRATION_ACCESS')]
    public function dashboard(Request $request, UrlGeneratorInterface $urls): RedirectResponse
    {
        $position = array_filter(self::listPosition($request), static fn(string $value): bool => '' !== $value);
        $runId = trim((string) $request->query->get('run', ''));

        return new RedirectResponse(
            '' === $runId
                ? $urls->generate('gplanchat_durable_plugin_admin_run_index', $position)
                : $urls->generate('gplanchat_durable_plugin_admin_run_show', ['runId' => $runId] + $position),
            Response::HTTP_MOVED_PERMANENTLY,
        );
    }

    /**
     * A missing worker fails nothing: executions stop at their first task of its kind, and the list
     * alone looks healthy. Asked only of a backend that answers, and only on the list page: the run
     * page is about one run. Uncached, like Magento's banner: a cluster that answers the health
     * check and then hangs costs one 5 s probe per role on this render.
     *
     * Without a workflow role, the journal is not on Temporal, and Messenger keeps no list of the
     * processes that consume its transports: one `unlisted` row says so, as the Filament page does
     * on Illuminate, after a Nexus role the cluster can describe. The in-memory backend runs every
     * task in the process that starts it, and gets no row.
     *
     * @param array<string, mixed> $backend
     *
     * @return list<array{role: string, pollers: int, polling: bool, error: ?string, seconds: int, unlisted?: true}>
     */
    private function workerRows(array $backend): array
    {
        if (true !== ($backend['available'] ?? false) || true === ($backend['ephemeral'] ?? false)) {
            return [];
        }
        $rows = [];
        $described = [];
        if (null !== $this->workers) {
            $since = $this->workers->since();
            $described = $this->workers->describe();
            foreach ($described as $role => $queue) {
                $rows[] = ['role' => $role, 'pollers' => $queue->pollers, 'polling' => $queue->polledSince($since), 'error' => $queue->error, 'seconds' => WorkerPresence::SILENCE_SECONDS];
            }
        }
        if (!isset($described['workflow'])) {
            $rows[] = ['role' => 'queue', 'pollers' => 0, 'polling' => false, 'error' => null, 'seconds' => WorkerPresence::SILENCE_SECONDS, 'unlisted' => true];
        }

        return $rows;
    }

    private function requireTheSyliusAdmin(): void
    {
        // The pages live in the Sylius admin and are composed by its hooks: an application without
        // that admin has no page here, rather than a Twig error on a missing layout (#383).
        if (!$this->twig->getLoader()->exists('@SyliusAdmin/shared/layout/base.html.twig')) {
            throw new NotFoundHttpException('The Durable dashboard lives in the Sylius admin, which this application does not have.');
        }
    }

    /**
     * Where the operator is in the list, which a run's page leads back to.
     *
     * @return array{status: string, cursor: string, workflowName: string, executionIdPrefix: string}
     */
    private static function listPosition(Request $request): array
    {
        $text = static fn(string $key): string => trim((string) $request->query->get($key, ''));

        return [
            'status' => $text('status'),
            'cursor' => $text('cursor'),
            'workflowName' => $text('workflowName'),
            'executionIdPrefix' => $text('executionIdPrefix'),
        ];
    }
}
