<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\DurableModule\Runtime\TableQueue\TableQueue;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture/magento-resource-connection.php';

/**
 * A message whose lease ends before the attempt claim does is delivered while the first copy still
 * holds the claim (#731, #753). The spike ran both at 30 s, and a redelivery ran only because the
 * claim had just expired: the queue refuses that configuration.
 */
final class TheTableQueueLeaseOutlastsTheAttemptClaimTest extends TestCase
{
    public function testALeaseNotLongerThanTheClaimTtlIsRefused(): void
    {
        foreach ([[30, 30], [29, 30], [0, 0]] as [$lease, $claim]) {
            try {
                new TableQueue(new class implements AdapterInterface {}, $lease, $claim);
                self::fail(\sprintf('a lease of %d s with a claim TTL of %d s was accepted', $lease, $claim));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('lease', $e->getMessage());
            }
        }
    }

    public function testALongerLeaseIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        new TableQueue(new class implements AdapterInterface {}, 31, 30);
    }

    public function testTheTwoDurationsComeFromEnvPhpAndDefaultTo600And300(): void
    {
        $adapter = new class implements AdapterInterface {};

        self::assertSame([600, 300], TableQueue::configuredDurations($this->config([])));
        self::assertSame([90, 60], TableQueue::configuredDurations($this->config(['durable/queue/lease_seconds' => 90, 'durable/queue/attempt_claim_ttl_seconds' => 60])));
        self::assertInstanceOf(TableQueue::class, TableQueue::configured($adapter, $this->config([])));
    }

    public function testAConfiguredLeaseNotLongerThanTheConfiguredClaimTtlIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TableQueue::configured(new class implements AdapterInterface {}, $this->config(['durable/queue/lease_seconds' => 60, 'durable/queue/attempt_claim_ttl_seconds' => 60]));
    }

    /** @param array<string, int> $values */
    private function config(array $values): DeploymentConfig
    {
        return new class ($values) extends DeploymentConfig {
            /** @param array<string, int> $values */
            public function __construct(private readonly array $values) {}

            public function get($key = null, $defaultValue = null)
            {
                return $this->values[$key] ?? $defaultValue;
            }
        };
    }
}
