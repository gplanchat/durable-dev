<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\Bridge\Temporal\Port\TemporalWorkflowResumeDispatcher;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\DurableModule\Runtime\InProcessWorkflowResumeDispatcher;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableModule\Runtime\UndeclaredWorkflowException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use unit\DurableModule\Fixture\BrokenOrderWorkflow;
use unit\DurableModule\Fixture\OrderActivities;
use unit\DurableModule\Fixture\OrderWorkflow;

/**
 * Magento starts a workflow with `dispatchNewWorkflowRun()` like the other hosts (#976): in this
 * process without a DSN, on the cluster with one.
 */
final class TheFactoryHandsOutAResumeDispatcherTest extends TestCase
{
    public function testWithoutADsnANewRunExecutesInThisProcess(): void
    {
        $charged = [];
        $activities = new class ($charged) implements OrderActivities {
            /** @param list<string> $charged */
            public function __construct(private array &$charged) {}

            public function charge(string $orderId): string
            {
                $this->charged[] = $orderId;

                return 'charge:' . $orderId;
            }

            public function reserveStock(string $orderId): string
            {
                return 'reserve:' . $orderId;
            }

            public function notifyCustomer(string $receipt): string
            {
                return 'notify:' . $receipt;
            }
        };
        $dispatcher = (new RuntimeFactory(workflowClasses: [OrderWorkflow::class], activityHandlers: [$activities]))->resumeDispatcher();

        self::assertInstanceOf(InProcessWorkflowResumeDispatcher::class, $dispatcher);
        $dispatcher->dispatchNewWorkflowRun(ExecutionId::fromString('order-1'), OrderWorkflow::class, ['orderId' => 'ORD-1']);

        self::assertSame(['ORD-1'], $charged);
    }

    public function testAFailingWorkflowDoesNotThrowFromTheDispatchAndIsLogged(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = $level . ': ' . $message;
            }
        };
        $dispatcher = (new RuntimeFactory(workflowClasses: [BrokenOrderWorkflow::class], logger: $logger))->resumeDispatcher();

        $dispatcher->dispatchNewWorkflowRun(ExecutionId::fromString('order-2'), BrokenOrderWorkflow::class, ['orderId' => 'ORD-2']);

        self::assertCount(1, $logger->messages);
        self::assertStringContainsString('order-2', $logger->messages[0]);
        self::assertStringContainsString('cannot be placed', $logger->messages[0]);
    }

    public function testAnUndeclaredWorkflowStillThrowsFromTheDispatch(): void
    {
        $dispatcher = (new RuntimeFactory(workflowClasses: [OrderWorkflow::class]))->resumeDispatcher();

        $this->expectException(UndeclaredWorkflowException::class);
        $dispatcher->dispatchNewWorkflowRun(ExecutionId::fromString('order-3'), BrokenOrderWorkflow::class, []);
    }

    public function testResumesAreNoOpsInProcess(): void
    {
        $dispatcher = (new RuntimeFactory(workflowClasses: [OrderWorkflow::class]))->resumeDispatcher();
        $dispatcher->dispatchResume(ExecutionId::fromString('order-1'));

        $this->expectNotToPerformAssertions();
    }

    public function testWithADsnTheDispatcherIsTheTemporalOne(): void
    {
        $dispatcher = (new RuntimeFactory(workflowClasses: [OrderWorkflow::class], temporalDsn: 'temporal+http://127.0.0.1:7243?namespace=default'))->resumeDispatcher();

        self::assertInstanceOf(TemporalWorkflowResumeDispatcher::class, $dispatcher);
    }
}
