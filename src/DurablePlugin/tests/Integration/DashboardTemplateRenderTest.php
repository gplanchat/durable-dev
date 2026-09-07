<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The template must no longer know anything about the backend that feeds it.
 *
 * These assertions are coarse — a file read — and that is deliberate: they guard a vocabulary
 * contract, not a rendering. What they prevent is precise: that a `temporal.` or a "task queue"
 * column comes back inadvertently into a page that has to serve two backends, only one of which
 * has those notions.
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
     * The assertion is about the keys and not about `kpis.<key>`: the page walks them in a loop
     * rather than writing them one by one, and demanding the dotted form would amount to freezing
     * the way of rendering instead of the vocabulary rendered.
     */
    public function testEveryOutcomeHasItsCounterOnThePage(): void
    {
        foreach (['total', 'running', 'completed', 'failed', 'cancelled', 'continued_as_new'] as $counter) {
            self::assertStringContainsString(
                \sprintf("'%s'", $counter),
                $this->template,
                \sprintf('the "%s" counter is missing', $counter),
            );
        }
    }

    public function testTheTemporalOnlyProviderIsGone(): void
    {
        self::assertFileDoesNotExist(
            \dirname(__DIR__, 2) . '/Dashboard/TemporalEventsDashboardDataProvider.php',
            'the gRPC provider moved to the Temporal bridge; the plugin no longer speaks to a backend',
        );
    }
}
