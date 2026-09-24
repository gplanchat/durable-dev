<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\EventListener\WarnOnIgnoredWorkerLimitsListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * The warning about --limit and --failure-limit (#353) exists where the names it inspects are the
 * Temporal workers: on the Temporal backend, and there only.
 */
final class DurableWorkerLimitsWarningWiringTest extends TestCase
{
    public function testOnTemporalTheWarningListensToEveryWorkerStart(): void
    {
        $container = $this->load(['temporal' => ['dsn' => 'temporal://127.0.0.1:7233?namespace=durable-test']]);

        self::assertTrue($container->hasDefinition(WarnOnIgnoredWorkerLimitsListener::class));
        self::assertSame(
            [['event' => WorkerStartedEvent::class]],
            $container->getDefinition(WarnOnIgnoredWorkerLimitsListener::class)->getTag('kernel.event_listener'),
        );
    }

    public function testOnTheJournalTheLimitsWorkAndNothingWarns(): void
    {
        self::assertFalse($this->load([])->hasDefinition(WarnOnIgnoredWorkerLimitsListener::class));
        self::assertFalse($this->load(['temporal' => ['dsn' => 'temporal://127.0.0.1:7233', 'journal' => false]])->hasDefinition(WarnOnIgnoredWorkerLimitsListener::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new DurableExtension())->load([$config], $container);

        return $container;
    }
}
