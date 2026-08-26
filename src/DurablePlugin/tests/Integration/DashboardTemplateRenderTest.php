<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Le gabarit ne doit plus rien savoir du backend qui l'alimente.
 *
 * Ces assertions sont grossières — une lecture de fichier — et c'est assumé : elles gardent un
 * contrat de vocabulaire, pas un rendu. Ce qu'elles empêchent est précis : qu'un `temporal.` ou
 * une colonne « file de tâches » revienne par inadvertance dans une page qui doit servir deux
 * backends dont un seul a ces notions.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/tasks.md §6.3
 */
final class DashboardTemplateRenderTest extends TestCase
{
    private string $template;

    protected function setUp(): void
    {
        $path = \dirname(__DIR__, 2) . '/Resources/views/admin/dashboard/index.html.twig';
        self::assertFileExists($path);

        $template = file_get_contents($path);
        self::assertIsString($template);
        $this->template = $template;
    }

    public function testTheTemplateSpeaksOfABackendAndNotOfTemporal(): void
    {
        self::assertStringContainsString('backend.message', $this->template);
        self::assertStringNotContainsString('temporal.', $this->template);
        self::assertStringNotContainsStringIgnoringCase('namespace', $this->template);
    }

    public function testTheTemplateShowsNoFactTheBackendMayNotHave(): void
    {
        self::assertStringNotContainsString('taskQueue', $this->template);
        self::assertStringNotContainsString('run.duration', $this->template);
    }

    public function testTheTemplateStillLivesInTheSyliusAdminLayout(): void
    {
        self::assertStringContainsString('@SyliusAdmin/shared/layout/base.html.twig', $this->template);
    }

    /**
     * L'assertion porte sur les clés et non sur `kpis.<clé>` : la page les parcourt en boucle plutôt
     * que de les écrire une à une, et exiger la forme pointée reviendrait à figer la manière de
     * rendre au lieu du vocabulaire rendu.
     */
    public function testEveryOutcomeHasItsCounterOnThePage(): void
    {
        foreach (['total', 'running', 'completed', 'failed', 'cancelled', 'continued_as_new'] as $counter) {
            self::assertStringContainsString(
                \sprintf("'%s'", $counter),
                $this->template,
                \sprintf('le compteur « %s » manque', $counter),
            );
        }
    }

    public function testTheTemporalOnlyProviderIsGone(): void
    {
        self::assertFileDoesNotExist(
            \dirname(__DIR__, 2) . '/Dashboard/TemporalEventsDashboardDataProvider.php',
            'le fournisseur gRPC a rejoint le pont Temporal ; le plugin ne parle plus à un backend',
        );
    }
}
