<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Unit;

use Gplanchat\Durable\Observation\PayloadRedactorInterface;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Plugin\DependencyInjection\DurablePluginExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The run page masks with the application's redactor, the one the profiler and diagnose use: an
 * application that masks `email` must not see it on the run page (#507, review of #526).
 */
final class TheRunPageUsesTheApplicationRedactorTest extends TestCase
{
    public function testTheDashboardIsHandedTheContainersRedactor(): void
    {
        $container = new ContainerBuilder();
        (new DurablePluginExtension())->load([], $container);

        $redactor = $container->getDefinition(RunDashboard::class)->getArgument('$redactor');

        self::assertInstanceOf(Reference::class, $redactor);
        self::assertSame(PayloadRedactorInterface::class, (string) $redactor);
    }
}
