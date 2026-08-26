<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Awaitable\AwaitableCancellation;
use Gplanchat\Durable\Awaitable\NexusOperationAwaitable;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;
use PHPUnit\Framework\TestCase;

/**
 * §3.2 et §3.5 — le workflow planifie une opération Nexus, et l'annulation la retrouve.
 *
 * Le tout repose sur le même mécanisme de **slot** que les activités : la position d'appel dans
 * l'exécution, et non un identifiant tiré au hasard, est ce qui permet au replay de retomber sur
 * la même opération. Un compteur oublié, et la deuxième passe planifierait une seconde fois ce
 * que la première a déjà lancé.
 */
final class NexusScheduleAndCancelTest extends TestCase
{
    /** @var list<string> */
    private array $scheduled = [];

    /** @var list<string> */
    private array $cancelled = [];

    public function testTwoOperationsTakeTwoSuccessiveSlots(): void
    {
        $buffer = $this->recordingBuffer();
        $context = $this->context($buffer, $this->emptyHistory());

        $context->nexusOperation(...$this->call('encaisser'));
        $context->nexusOperation(...$this->call('rembourser'));

        self::assertCount(2, $this->scheduled);
        self::assertNotSame(
            $this->scheduled[0],
            $this->scheduled[1],
            'Deux appels distincts ne peuvent pas partager une identité.',
        );
    }

    public function testAReplayedOperationIsNotScheduledASecondTime(): void
    {
        // La panne que le slot existe pour empêcher : rejouer, c'est reconstruire l'état, pas
        // relancer l'appel. Une opération relancée à chaque passe serait facturée à chaque passe.
        $history = $this->historyWith(0, ['result' => ['ok' => true], 'failed' => null], 'op-rejoue');
        $buffer = $this->recordingBuffer();
        $context = $this->context($buffer, $history);

        $awaitable = $context->nexusOperation(...$this->call('encaisser'));

        self::assertSame([], $this->scheduled, 'Une opération déjà journalisée a été replanifiée.');
        self::assertTrue($awaitable->isSettled());
        self::assertSame(['ok' => true], $awaitable->getResult());
        self::assertInstanceOf(NexusOperationAwaitable::class, $awaitable);
        self::assertSame('op-rejoue', $awaitable->operationId());
    }

    public function testAnOperationAlreadyScheduledKeepsItsRecordedIdentity(): void
    {
        // Planifiée mais pas encore réglée : la commande ne doit pas repartir, et l'identité doit
        // être celle de l'historique — sinon l'annulation viserait une opération qui n'existe pas.
        $history = $this->historyWith(0, null, 'op-en-vol');
        $buffer = $this->recordingBuffer();
        $context = $this->context($buffer, $history);

        $awaitable = $context->nexusOperation(...$this->call('encaisser'));

        self::assertSame([], $this->scheduled);
        self::assertSame('op-en-vol', $awaitable->operationId());
        self::assertFalse($awaitable->isSettled());
    }

    public function testCancellingAWorkflowReachesAPendingNexusOperation(): void
    {
        // §3.5 : sans sa branche dans la marche d'annulation, l'opération resterait en vol après
        // l'annulation du workflow — exactement l'activité orpheline que DUR033 a fait disparaître.
        $buffer = $this->recordingBuffer();
        $context = $this->context($buffer, $this->emptyHistory());
        $awaitable = $context->nexusOperation(...$this->call('encaisser'));

        $cancelled = AwaitableCancellation::cancelUnsettled($context, $awaitable, 'race_superseded');

        self::assertSame([$awaitable->operationId()], $cancelled);
        self::assertSame([$awaitable->operationId()], $this->cancelled);
    }

    public function testASettledOperationIsNotCancelled(): void
    {
        $history = $this->historyWith(0, ['result' => 'fini', 'failed' => null], 'op-fini');
        $buffer = $this->recordingBuffer();
        $context = $this->context($buffer, $history);
        $awaitable = $context->nexusOperation(...$this->call('encaisser'));

        self::assertSame([], AwaitableCancellation::cancelUnsettled($context, $awaitable, 'race_superseded'));
        self::assertSame([], $this->cancelled);
    }

    /** @return array{NexusEndpoint, NexusService, NexusOperationName, array<string, mixed>, NexusOperationTimeouts} */
    private function call(string $operation): array
    {
        return [
            NexusEndpoint::named('paiements'),
            NexusService::named('facturation'),
            NexusOperationName::named($operation),
            ['montant' => 100],
            new NexusOperationTimeouts(scheduleToClose: Duration::seconds(30.0)),
        ];
    }

    private function context(WorkflowCommandBufferInterface $buffer, WorkflowHistorySourceInterface $history): ExecutionContext
    {
        return new ExecutionContext('exec-nexus', $history, $buffer);
    }

    /**
     * Un tampon qui note ce qu'on lui demande. Les autres commandes ne concernent pas cette
     * tranche : le stub les accepte sans rien faire.
     */
    private function recordingBuffer(): WorkflowCommandBufferInterface
    {
        $buffer = $this->createStub(WorkflowCommandBufferInterface::class);
        $buffer->method('scheduleNexusOperation')->willReturnCallback(
            function (string $operationId) {
                $this->scheduled[] = $operationId;
            },
        );
        $buffer->method('cancelNexusOperation')->willReturnCallback(
            function (string $operationId) {
                $this->cancelled[] = $operationId;
            },
        );

        return $buffer;
    }

    /**
     * @param array{result: mixed, failed: \Throwable|null}|null $result
     */
    private function historyWith(int $slot, ?array $result, ?string $operationId): WorkflowHistorySourceInterface
    {
        $history = $this->createStub(WorkflowHistorySourceInterface::class);
        $history->method('findNexusOperationSlotResult')->willReturnCallback(
            static fn(int $s): ?array => $s === $slot ? $result : null,
        );
        $history->method('findScheduledNexusOperation')->willReturnCallback(
            static fn(int $s): ?string => $s === $slot ? $operationId : null,
        );

        return $history;
    }

    private function emptyHistory(): WorkflowHistorySourceInterface
    {
        return $this->historyWith(-1, null, null);
    }
}
