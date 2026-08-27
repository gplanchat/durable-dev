<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use PHPUnit\Framework\TestCase;

/**
 * Nexus est inter-namespace par nature : appeler une opération servie par une autre équipe n'a
 * aucun équivalent dans un journal local. La proposition l'écrit — le backend journal **refuse**
 * l'appel avec une erreur explicite plutôt que de faire semblant.
 *
 * Ce refus n'est pas une lacune à combler plus tard : c'est le comportement voulu. Un backend qui
 * accepterait la commande et n'en ferait rien laisserait le workflow attendre un résultat que
 * personne ne produira — la panne muette, encore.
 *
 * @see openspec/changes/temporal-nexus-support/proposal.md
 * @see openspec/changes/temporal-nexus-support/tasks.md §3.4
 */
final class NexusUnsupportedByJournalBackendTest extends TestCase
{
    public function testSchedulingIsRefusedWithAnExplicitError(): void
    {
        $this->expectException(NexusUnsupportedByBackendException::class);
        $this->expectExceptionMessage('Nexus');

        $this->buffer()->scheduleNexusOperation(
            'op-1',
            NexusEndpoint::named('billing-endpoint'),
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            ['amount' => 10],
            NexusOperationTimeouts::none(),
            NexusOperationHeaders::none(),
        );
    }

    public function testCancellingIsRefusedTheSameWay(): void
    {
        $this->expectException(NexusUnsupportedByBackendException::class);

        $this->buffer()->cancelNexusOperation('op-1', 'workflow cancelled');
    }

    public function testTheErrorNamesTheBackendAndPointsAtTemporal(): void
    {
        // Le message doit dire quoi faire, pas seulement que c'est impossible : le lecteur est un
        // développeur qui vient d'écrire un appel Nexus et ne sait pas encore que son backend ne
        // peut pas le servir.
        try {
            $this->buffer()->cancelNexusOperation('op-1', 'peu importe');
            self::fail('Le backend journal a accepté une opération Nexus.');
        } catch (NexusUnsupportedByBackendException $e) {
            self::assertStringContainsString('Temporal', $e->getMessage());
        }
    }

    private function buffer(): EventStoreCommandBuffer
    {
        return new EventStoreCommandBuffer(
            new InMemoryEventStore(),
            new NoopActivityTransport(),
            'exec-1',
        );
    }
}
