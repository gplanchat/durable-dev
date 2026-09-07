<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Awaitable;

use Gplanchat\Durable\Awaitable\Deferred;
use Gplanchat\Durable\Awaitable\NexusOperationAwaitable;
use PHPUnit\Framework\TestCase;

/**
 * The awaitable of a Nexus operation carries what it takes to cancel it.
 *
 * With no identity carried, an `any(nexusOperation, timer)` whose timer wins would leave the
 * operation running at the provider: the wait is over on the workflow side, the call is not. It is
 * the exact role {@see \Gplanchat\Durable\Awaitable\ActivityAwaitable} plays for an activity, and
 * {@see \Gplanchat\Durable\Awaitable\AwaitableCancellation} depends on it.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §3.1
 */
final class NexusOperationAwaitableTest extends TestCase
{
    public function testItCarriesTheOperationIdentity(): void
    {
        $awaitable = new NexusOperationAwaitable((new Deferred())->awaitable(), 'nexus-op-7');

        self::assertSame('nexus-op-7', $awaitable->operationId());
    }

    public function testItIsNotSettledWhileTheOperationIsPending(): void
    {
        $awaitable = new NexusOperationAwaitable((new Deferred())->awaitable(), 'nexus-op-7');

        self::assertFalse($awaitable->isSettled());
    }

    public function testItSettlesWithTheOperationResult(): void
    {
        $deferred = new Deferred();
        $awaitable = new NexusOperationAwaitable($deferred->awaitable(), 'nexus-op-7');

        $deferred->resolve(['ok' => true]);

        self::assertTrue($awaitable->isSettled());
        self::assertSame(['ok' => true], $awaitable->getResult());
    }

    public function testItExposesTheWrappedAwaitable(): void
    {
        // AwaitableCancellation and the composites descend through inner(): without it, a Nexus
        // operation buried under a composite would be invisible to cancellation.
        $inner = (new Deferred())->awaitable();
        $awaitable = new NexusOperationAwaitable($inner, 'nexus-op-7');

        self::assertSame($inner, $awaitable->inner());
    }
}
