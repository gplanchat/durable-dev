<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Durable writes `durableExecutionId` and `durableWaitingOn` in a child's memo itself, on every
 * backend (#889): a user value under either is refused when the options are built.
 */
final class ChildWorkflowOptionsReservedMemoKeysTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function reservedKeys(): iterable
    {
        yield 'execution id' => ['durableExecutionId'];
        yield 'waiting on' => ['durableWaitingOn'];
    }

    #[DataProvider('reservedKeys')]
    public function testAReservedMemoKeyIsRefused(string $key): void
    {
        $this->expectException(UnsupportedByBackendException::class);
        $this->expectExceptionMessage(\sprintf('The key "%s" in ChildWorkflowOptions::$memo is reserved: Durable writes this key itself; choose another key.', $key));

        new ChildWorkflowOptions(memo: ['orderId' => 'ORD-4242', $key => 'x']);
    }

    public function testTheReservedKeysAreTheOnesTheTemporalBridgeReads(): void
    {
        self::assertSame('durableExecutionId', ChildWorkflowOptions::MEMO_KEY_DURABLE_EXECUTION_ID);
        self::assertSame('durableWaitingOn', ChildWorkflowOptions::MEMO_KEY_DURABLE_WAITING_ON);
    }

    public function testAnyOtherMemoKeyIsAccepted(): void
    {
        self::assertSame(['orderId' => 'ORD-4242'], (new ChildWorkflowOptions(memo: ['orderId' => 'ORD-4242']))->memo);
    }
}
