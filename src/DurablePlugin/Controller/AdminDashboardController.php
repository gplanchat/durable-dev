<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Controller;

use Gplanchat\Durable\Observation\RunDashboard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        $status = trim((string) $request->query->get('status', 'all'));
        $cursor = trim((string) $request->query->get('cursor', ''));
        $selectedRunId = trim((string) $request->query->get('run', ''));
        $back = self::decodeBack((string) $request->query->get('back', ''));

        $model = $view->build(
            '' === $status ? 'all' : $status,
            '' === $cursor ? null : $cursor,
            '' === $selectedRunId ? null : $selectedRunId,
        );
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
