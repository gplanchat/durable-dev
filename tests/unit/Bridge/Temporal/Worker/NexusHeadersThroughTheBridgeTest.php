<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use PHPUnit\Framework\TestCase;

/**
 * §3.1 et §3.2 — les en-têtes traversent le port et atteignent la commande.
 *
 * Prises ensemble : élargir le port sans que le pont n'écrive rien livrerait un paramètre accepté
 * puis ignoré, ce qui est pire que de ne pas l'avoir — un appelant croirait poser un en-tête.
 *
 * Ce que ces tests ne peuvent pas dire : si le serveur accepte. C'est §4.1, contre un vrai
 * serveur, et c'est déjà ce qui a manqué à d'autres commandes de ce pont.
 */
final class NexusHeadersThroughTheBridgeTest extends TestCase
{
    public function testTheHeadersReachTheCommand(): void
    {
        $command = $this->schedule(NexusOperationHeaders::of(['x-correlation' => 'abc-123', 'x-tenant' => 'acme']));

        $written = [];
        foreach ($command->getScheduleNexusOperationCommandAttributes()?->getNexusHeader() ?? [] as $k => $v) {
            $written[(string) $k] = (string) $v;
        }
        ksort($written);

        self::assertSame(['x-correlation' => 'abc-123', 'x-tenant' => 'acme'], $written);
    }

    public function testAKeyGivenInUpperCaseTravelsLowercased(): void
    {
        // La coercition appartient à l'objet-valeur, pas au pont : ce que l'appelant tient doit
        // déjà être ce que le serveur gardera, sinon la relecture de sa propre valeur ment.
        $command = $this->schedule(NexusOperationHeaders::of(['X-Correlation' => 'abc-123']));

        $written = [];
        foreach ($command->getScheduleNexusOperationCommandAttributes()?->getNexusHeader() ?? [] as $k => $v) {
            $written[(string) $k] = (string) $v;
        }

        self::assertSame(['x-correlation' => 'abc-123'], $written);
    }

    public function testNoHeaderWritesNoHeader(): void
    {
        // Une map vide n'est pas la même chose qu'une map absente pour qui relit un historique.
        $command = $this->schedule(NexusOperationHeaders::none());

        self::assertCount(0, $command->getScheduleNexusOperationCommandAttributes()?->getNexusHeader() ?? []);
    }

    private function schedule(NexusOperationHeaders $headers): \Temporal\Api\Command\V1\Command
    {
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'exec-1');
        $buffer->scheduleNexusOperation(
            'op-1',
            NexusEndpoint::named('paiements'),
            NexusService::named('facturation'),
            NexusOperationName::named('encaisser'),
            ['montant' => 10],
            new NexusOperationTimeouts(scheduleToClose: Duration::seconds(30.0)),
            $headers,
        );

        $commands = $buffer->flush();
        self::assertCount(1, $commands);

        return $commands[0];
    }
}
