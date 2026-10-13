<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Exception;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\ExceptionInterface;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowResultTimeout;
use Gplanchat\Durable\Exception\WorkflowStartOptionsRefused;
use PHPUnit\Framework\TestCase;

final class TheClientPortExceptionsCarryTheirContextTest extends TestCase
{
    public function testAlreadyStartedNamesTheId(): void
    {
        $e = new WorkflowExecutionAlreadyStarted('exec-1');

        self::assertSame('exec-1', $e->executionId);
        self::assertInstanceOf(ExceptionInterface::class, $e);
    }

    public function testAlreadyEndedNamesTheId(): void
    {
        $e = new WorkflowExecutionAlreadyEnded('exec-1');

        self::assertSame('exec-1', $e->executionId);
        self::assertInstanceOf(ExceptionInterface::class, $e);
    }

    public function testAResultTimeoutNamesTheIdAndTheBound(): void
    {
        $e = new WorkflowResultTimeout('exec-1', Duration::seconds(2));

        self::assertSame('exec-1', $e->executionId);
        self::assertEquals(Duration::seconds(2), $e->timeout);
        self::assertInstanceOf(ExceptionInterface::class, $e);
    }

    public function testRefusedOptionsNameEveryOptionAndTheBackend(): void
    {
        $e = new WorkflowStartOptionsRefused('in-memory', ['taskQueue', 'cronSchedule']);

        self::assertSame('in-memory', $e->backend);
        self::assertSame(['taskQueue', 'cronSchedule'], $e->options);
        self::assertInstanceOf(ExceptionInterface::class, $e);
    }
}
