<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\Bridge\Temporal\Worker\TemporalActivityHeartbeatSender;
use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableModule\Runtime\SharedActivityHeartbeatSender;
use PHPUnit\Framework\TestCase;

/**
 * The ObjectManager builds the activities; the factory builds the worker. On Temporal the worker
 * binds each task's token onto its sender, so the sender the activities inject has to lead to
 * that very instance, or their heartbeats never reach the cluster (#510).
 */
final class TheActivitiesShareTheWorkersHeartbeatSenderTest extends TestCase
{
    public function testOnTemporalTheSharedSenderLeadsToTheWorkersOne(): void
    {
        $shared = new SharedActivityHeartbeatSender();
        $factory = new RuntimeFactory(temporalDsn: 'temporal://127.0.0.1:7233?namespace=default', heartbeat: $shared);

        $worker = $factory->activityWorker();
        $sender = (new \ReflectionProperty($worker, 'heartbeatSender'))->getValue($worker);
        $processor = (new \ReflectionProperty($worker, 'processor'))->getValue($worker);

        self::assertInstanceOf(TemporalActivityHeartbeatSender::class, $sender);
        self::assertSame($sender, (new \ReflectionProperty($processor, 'heartbeatSender'))->getValue($processor));
        self::assertSame($sender, $shared->inner());
        self::assertSame($sender, (new \ReflectionProperty($factory->activityWorker(), 'heartbeatSender'))->getValue($factory->activityWorker()), 'one sender per factory');
    }

    public function testWithoutTheClusterTheSharedSenderStaysANoOp(): void
    {
        $shared = new SharedActivityHeartbeatSender();

        self::assertInstanceOf(NullActivityHeartbeatSender::class, $shared->inner());
        self::assertFalse($shared->sendHeartbeat(['progress' => 1]));
        self::assertFalse($shared->isCancellationRequested());
    }
}
