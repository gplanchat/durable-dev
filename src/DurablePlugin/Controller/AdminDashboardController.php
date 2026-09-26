<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Controller;

use Gplanchat\Durable\Observation\RunDashboard;
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
final class AdminDashboardController
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    #[IsGranted('ROLE_ADMINISTRATION_ACCESS')]
    public function index(Request $request, RunDashboard $view): Response
    {
        $this->requireTheSyliusAdmin();
        ['status' => $status, 'cursor' => $cursor, 'back' => $encodedBack] = self::listPosition($request);
        $back = self::decodeBack($encodedBack);

        $model = $view->listing('' === $status ? 'all' : $status, '' === $cursor ? null : $cursor);
        // The catalog pages forward only (Temporal's visibility has no reverse cursor), so the way
        // back is the stack of cursors the operator came through, '' standing for the first page.
        // ponytail: the stack grows with each page in the URL; cap it if operators page that deep.
        $model['pagination']['back'] = self::encodeBack($back);
        $model['pagination']['nextBack'] = self::encodeBack([...$back, $cursor]);
        $model['pagination']['previous'] = [] === $back ? null : [
            'cursor' => $back[array_key_last($back)],
            'back' => self::encodeBack(\array_slice($back, 0, -1)),
        ];

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

    private function requireTheSyliusAdmin(): void
    {
        // The pages live in the Sylius admin and are composed by its hooks: an application without
        // that admin has no page here, rather than a Twig error on a missing layout (#383).
        if (!$this->twig->getLoader()->exists('@SyliusAdmin/shared/layout/base.html.twig')) {
            throw new NotFoundHttpException('The Durable dashboard lives in the Sylius admin, which this application does not have.');
        }
    }

    /** @return array{status: string, cursor: string, back: string} */
    private static function listPosition(Request $request): array
    {
        return [
            'status' => trim((string) $request->query->get('status', '')),
            'cursor' => trim((string) $request->query->get('cursor', '')),
            'back' => (string) $request->query->get('back', ''),
        ];
    }

    /** @param list<string> $cursors */
    private static function encodeBack(array $cursors): string
    {
        return rtrim(strtr(base64_encode(json_encode($cursors, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return list<string> a stack that does not decode is no way back, not an error */
    private static function decodeBack(string $encoded): array
    {
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $cursors = false === $json ? null : json_decode($json, true);

        return \is_array($cursors) && array_is_list($cursors) && [] === array_filter($cursors, static fn(mixed $c): bool => !\is_string($c))
            ? $cursors
            : [];
    }
}
