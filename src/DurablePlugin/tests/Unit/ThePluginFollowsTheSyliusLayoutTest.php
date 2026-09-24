<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Sylius 2 plugin layout: `config/` and `templates/` at the package root, and an admin route
 * that follows the shop's admin prefix instead of assuming `/admin` (M26, M27).
 */
final class ThePluginFollowsTheSyliusLayoutTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testConfigurationAndTemplatesSitAtThePackageRoot(): void
    {
        self::assertFileExists(self::ROOT . '/config/routes.yaml');
        self::assertFileExists(self::ROOT . '/config/services.php');
        self::assertFileExists(self::ROOT . '/templates/admin/dashboard/_dashboard.html.twig');
        self::assertDirectoryDoesNotExist(self::ROOT . '/Resources');
    }

    public function testTheDashboardRouteFollowsTheAdminPrefix(): void
    {
        // symfony/yaml is not a dependency of the root suite: the one line is read as text.
        self::assertMatchesRegularExpression(
            '~^\s+path: /%sylius_admin\.path_name%/durable/dashboard$~m',
            (string) file_get_contents(self::ROOT . '/config/routes.yaml'),
        );
    }

    public function testThePackageSaysItIsASyliusPlugin(): void
    {
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('sylius-plugin', $composer['type']);
    }
}
