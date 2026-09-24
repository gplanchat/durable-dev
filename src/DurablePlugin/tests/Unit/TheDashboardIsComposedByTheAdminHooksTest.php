<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Unit;

use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Plugin\Controller\AdminDashboardController;
use Gplanchat\Durable\Plugin\DependencyInjection\DurablePluginExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The page is composed the way Sylius composes its own dashboard: sidebar, navbar, flashes and
 * footer come from `sylius_admin.common.index`, the content from this plugin (#383). Sylius stays
 * optional: without its hooks nothing is declared, and without its admin the page is not found.
 */
final class TheDashboardIsComposedByTheAdminHooksTest extends TestCase
{
    private const PAGE = __DIR__ . '/../../templates/admin/dashboard/index.html.twig';

    public function testThePageAsksForItsOwnHookBeforeTheCommonOne(): void
    {
        $page = (string) file_get_contents(self::PAGE);

        self::assertStringContainsString("{% hook ['sylius_admin.durable_dashboard.index', 'sylius_admin.common.index']", $page);
        self::assertStringNotContainsString('sidebar.html.twig', $page, 'the common hook renders the sidebar');
        self::assertStringNotContainsString('navbar.html.twig', $page, 'the common hook renders the navbar');
    }

    public function testTheContentHooksAreDeclaredWhenSyliusTwigHooksIsThere(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void {}

            public function getAlias(): string
            {
                return 'sylius_twig_hooks';
            }
        });

        (new DurablePluginExtension())->prepend($container);

        $content = $container->getExtensionConfig('sylius_twig_hooks')[0]['hooks']['sylius_admin.durable_dashboard.index.content'] ?? null;
        self::assertSame('@DurablePlugin/admin/dashboard/index/content/dashboard.html.twig', $content['dashboard']['template'] ?? null);
        self::assertFalse($content['grid']['enabled'] ?? true, 'no Sylius grid: the list is the dashboard\'s');
        self::assertFalse($content['header']['enabled'] ?? true, 'the dashboard carries its own heading');
    }

    public function testNothingIsDeclaredWithoutSyliusTwigHooks(): void
    {
        $container = new ContainerBuilder();

        (new DurablePluginExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('sylius_twig_hooks'));
    }

    public function testWithoutTheSyliusAdminThePageIsNotFoundRatherThanATwigError(): void
    {
        $twig = new Environment(new ArrayLoader([]));

        $this->expectException(NotFoundHttpException::class);

        (new AdminDashboardController($twig))->index(new Request(), new RunDashboard(null));
    }
}
