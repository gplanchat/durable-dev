<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Controller;

use Gplanchat\Durable\Observation\RunDashboard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

/**
 * La page ne fait plus que transporter : le filtre et le curseur vers le catalogue, le modèle de
 * vue vers le gabarit. Tout ce qui savait parler gRPC a rejoint le pont Temporal.
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

        return new Response($this->twig->render(
            '@DurablePlugin/admin/dashboard/index.html.twig',
            $view->build(
                '' === $status ? 'all' : $status,
                '' === $cursor ? null : $cursor,
                '' === $selectedRunId ? null : $selectedRunId,
            ),
        ));
    }
}
