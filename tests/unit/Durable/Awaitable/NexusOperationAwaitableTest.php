<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Awaitable;

use Gplanchat\Durable\Awaitable\Deferred;
use Gplanchat\Durable\Awaitable\NexusOperationAwaitable;
use PHPUnit\Framework\TestCase;

/**
 * L'awaitable d'une opération Nexus porte de quoi l'annuler.
 *
 * Sans identité transportée, un `any(nexusOperation, timer)` dont le minuteur gagne laisserait
 * l'opération tourner chez le fournisseur : l'attente est finie côté workflow, l'appel ne l'est
 * pas. C'est le rôle exact que {@see \Gplanchat\Durable\Awaitable\ActivityAwaitable} joue pour une
 * activité, et {@see \Gplanchat\Durable\Awaitable\AwaitableCancellation} en dépend.
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
        // AwaitableCancellation et les composites descendent par inner() : sans lui, une opération
        // Nexus enfouie sous un composite serait invisible à l'annulation.
        $inner = (new Deferred())->awaitable();
        $awaitable = new NexusOperationAwaitable($inner, 'nexus-op-7');

        self::assertSame($inner, $awaitable->inner());
    }
}
