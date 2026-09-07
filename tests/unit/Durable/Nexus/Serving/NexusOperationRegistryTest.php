<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;
use Gplanchat\Durable\Nexus\Serving\NexusHandlerErrorType;
use Gplanchat\Durable\Nexus\Serving\NexusOperationNotHandledException;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Nexus\Serving\NexusOperationResponse;
use PHPUnit\Framework\TestCase;

final class NexusOperationRegistryTest extends TestCase
{
    public function testABackendThatCannotRouteRefusesTheHandlerAtRegistration(): void
    {
        // The failure mode this guard exists to make impossible: on a backend with no routing, a
        // declared handler is not a call that fails, it is a service that never receives anything
        // — no error, no log line, a queue nobody polls.
        //
        // The Symfony bundle's compiler pass already caught it. It only catches Symfony: the
        // Magento module and the Illuminate bridge wire their services differently, and had
        // nothing. The guard therefore belongs to the core.
        $registry = NexusOperationRegistry::unavailableOn('memory');

        $this->expectException(NexusUnsupportedByBackendException::class);
        $this->expectExceptionMessageMatches('/memory/');
        $this->expectExceptionMessageMatches('/never receives/');

        $registry->register(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            static fn(): NexusOperationResponse => NexusOperationResponse::completed(null),
        );
    }

    public function testARegistryOnARoutingBackendServesNormally(): void
    {
        $registry = NexusOperationRegistry::routedBy('temporal');
        $registry->register(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            static fn(): NexusOperationResponse => NexusOperationResponse::completed(['ok' => true]),
        );

        self::assertTrue($registry->serves(NexusService::named('billing'), NexusOperationName::named('charge')));
    }

    public function testADeclaredOperationReceivesItsCallersPayload(): void
    {
        $seen = null;
        $registry = NexusOperationRegistry::routedBy('temporal');
        $registry->register(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            static function (mixed $payload) use (&$seen): NexusOperationResponse {
                $seen = $payload;

                return NexusOperationResponse::completed(['ok' => true]);
            },
        );

        $response = $registry->dispatch(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            ['amount' => 10],
        );

        self::assertSame(['amount' => 10], $seen);
        self::assertTrue($response->isImmediate);
        self::assertSame(['ok' => true], $response->result);
    }

    public function testAnOperationFulfilledByAWorkflowNeedsNoHandler(): void
    {
        // The declared deferred form: no handler is called, and there is none to write. The
        // registry returns directly the response the worker knows how to process — start the
        // workflow with the task callback attached.
        $registry = NexusOperationRegistry::routedBy('temporal');
        $registry->registerFulfilment(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            'ChargeWorkflow',
        );

        $response = $registry->dispatch(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            ['order' => 'o-1'],
        );

        self::assertFalse($response->isImmediate);
        self::assertSame('ChargeWorkflow', $response->workflowType);
        self::assertSame(['order' => 'o-1'], $response->workflowInput, "the caller's payload becomes the workflow's input");
    }

    public function testAFulfilledOperationIsServed(): void
    {
        $registry = NexusOperationRegistry::routedBy('temporal');
        $registry->registerFulfilment(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            'ChargeWorkflow',
        );

        self::assertTrue($registry->serves(NexusService::named('billing'), NexusOperationName::named('charge')));
    }

    public function testAFulfilmentOnABackendThatCannotRouteIsRefusedToo(): void
    {
        // Same guard as for a handler: with no routing, this workflow will never be started by
        // anyone, and nothing would say so.
        $registry = NexusOperationRegistry::unavailableOn('memory');

        $this->expectException(NexusUnsupportedByBackendException::class);

        $registry->registerFulfilment(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            'ChargeWorkflow',
        );
    }

    public function testAnOperationNobodyServesIsRefusedTerminally(): void
    {
        // 1b.3: NOT_IMPLEMENTED is on the non-retryable side. Calling it retryable would have
        // the same operation asked again every ~9 s for its whole budget, for an answer that
        // will not change.
        $registry = NexusOperationRegistry::routedBy('temporal');

        try {
            $registry->dispatch(NexusService::named('billing'), NexusOperationName::named('refund'), null);
            self::fail('An operation nobody serves must be refused.');
        } catch (NexusOperationNotHandledException $refusal) {
            self::assertSame(NexusHandlerErrorType::NotImplemented, $refusal->type());
            self::assertFalse($refusal->type()->isRetryable());
            self::assertStringContainsString('refund', $refusal->getMessage());
            self::assertStringContainsString('billing', $refusal->getMessage());
        }
    }

    public function testServingOneOperationDoesNotServeItsNeighbours(): void
    {
        $registry = NexusOperationRegistry::routedBy('temporal');
        $registry->register(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            static fn(): NexusOperationResponse => NexusOperationResponse::completed(null),
        );

        self::assertTrue($registry->serves(NexusService::named('billing'), NexusOperationName::named('charge')));
        self::assertFalse($registry->serves(NexusService::named('billing'), NexusOperationName::named('refund')));
        self::assertFalse($registry->serves(NexusService::named('shipping'), NexusOperationName::named('charge')));
    }

    public function testTwoOperationsWhoseNamesSplitDifferentlyDoNotCollide(): void
    {
        // A key joined by a dot would confuse ("a.b", "c") with ("a", "b.c").
        $registry = NexusOperationRegistry::routedBy('temporal');
        $registry->register(
            NexusService::named('a.b'),
            NexusOperationName::named('c'),
            static fn(): NexusOperationResponse => NexusOperationResponse::completed('premier'),
        );
        $registry->register(
            NexusService::named('a'),
            NexusOperationName::named('b.c'),
            static fn(): NexusOperationResponse => NexusOperationResponse::completed('second'),
        );

        self::assertSame('premier', $registry->dispatch(NexusService::named('a.b'), NexusOperationName::named('c'), null)->result);
        self::assertSame('second', $registry->dispatch(NexusService::named('a'), NexusOperationName::named('b.c'), null)->result);
    }
}
