<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class DashboardTemplateRenderTest extends TestCase
{
    public function testDashboardTemplateContainsTimelineBarClass(): void
    {
        $templatePath = dirname(__DIR__, 2) . '/Resources/views/admin/dashboard/index.html.twig';
        self::assertFileExists($templatePath);

        $template = file_get_contents($templatePath);
        self::assertIsString($template);
        self::assertStringContainsString('class="durable-bar {{ lane.kind }}"', $template);
        self::assertStringContainsString('@SyliusAdmin/shared/layout/base.html.twig', $template);
        self::assertStringContainsString('temporal.message', $template);
        self::assertStringContainsString('Last successful sync', $template);
        self::assertStringContainsString('Refresh', $template);
    }
}
