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
        // Le mode d'échec que ce garde existe pour rendre impossible : sur un backend sans route,
        // un gestionnaire déclaré n'est pas un appel qui échoue, c'est un service qui ne reçoit
        // jamais rien — aucune erreur, aucune ligne de log, une file que personne ne poll.
        //
        // La passe de compilation du bundle Symfony l'attrapait déjà. Elle n'attrape que Symfony :
        // le module Magento et le pont Illuminate montent leurs services autrement, et n'avaient
        // rien. Le garde appartient donc au cœur.
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

    public function testAnOperationNobodyServesIsRefusedTerminally(): void
    {
        // 1b.3 : NOT_IMPLEMENTED est du côté non réessayable. Le dire réessayable ferait
        // redemander la même opération toutes les ~9 s pendant tout son budget, pour une
        // réponse qui ne changera pas.
        $registry = NexusOperationRegistry::routedBy('temporal');

        try {
            $registry->dispatch(NexusService::named('billing'), NexusOperationName::named('refund'), null);
            self::fail('Une opération non servie doit être refusée.');
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
        // Une clé jointe par un point confondrait ("a.b", "c") et ("a", "b.c").
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
