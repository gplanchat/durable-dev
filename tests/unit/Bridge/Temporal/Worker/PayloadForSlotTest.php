<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Codec\TemporalActivityScheduleInput;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\NexusOperationScheduledEventAttributes;
use Temporal\Api\History\V1\StartChildWorkflowExecutionInitiatedEventAttributes;

/**
 * La garde de charge (DUR042) relit trois formes de fil, et **elles ne se valent pas**.
 *
 * C'est là que se cacherait une erreur de désenveloppage : les trois ressemblent à « l'entrée de
 * l'appel », et chacune est écrite autrement par le tampon.
 *
 * - activité — `Payloads` d'un élément, portant l'enveloppe
 *   {@see TemporalActivityScheduleInput} `{executionId, activityId, activityName, payload, metadata}` ;
 * - Nexus — un `Payload` **nu**, la charge de l'appelant sans enveloppe ;
 * - enfant — `Payloads` d'un élément, portant l'input **nu**.
 *
 * Les fixtures sont écrites d'après ce que `TemporalWorkflowCommandBuffer` produit réellement, et
 * non d'après le voisin : la fixture de {@see NexusSlotDivergenceTest} est antérieure au retrait de
 * l'enveloppe Nexus (tâche 1.1) et porte encore `{operationId, payload}`. Ne pas aligner celle-ci
 * dessus — c'est l'ancienne forme qui est périmée, pas celle-ci.
 */
final class PayloadForSlotTest extends TestCase
{
    public function testTheActivityPayloadIsReadFromInsideItsEnvelope(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->activityScheduled(5, 'act-1', 'weather', ['city' => 'Paris']),
        ]);

        // Les arguments, pas l'enveloppe : rendre l'enveloppe comparerait `activityName` deux fois
        // et laisserait passer un changement d'argument.
        self::assertSame(['city' => 'Paris'], $history->activityPayloadForSlot(0));
    }

    public function testTheNexusPayloadIsReadNaked(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->nexusScheduled(5, 'paiements', 'facturation', 'encaisser', ['amount' => 90]),
        ]);

        self::assertSame(['amount' => 90], $history->nexusOperationPayloadForSlot(0));
    }

    public function testTheChildInputIsReadFromItsSinglePayload(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->childScheduled('child-1', 'ChargeCardWorkflow', ['sku' => 'ABC']),
        ]);

        self::assertSame(['sku' => 'ABC'], $history->childWorkflowInputForSlot(0));
    }

    public function testEachSlotKeepsItsOwnPayload(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->nexusScheduled(5, 'paiements', 'facturation', 'encaisser', ['amount' => 90]),
            $this->nexusScheduled(9, 'stocks', 'entrepot', 'reserver', ['sku' => 'ABC']),
            $this->childScheduled('child-1', 'A', ['n' => 1]),
            $this->childScheduled('child-2', 'B', ['n' => 2]),
        ]);

        self::assertSame(['amount' => 90], $history->nexusOperationPayloadForSlot(0));
        self::assertSame(['sku' => 'ABC'], $history->nexusOperationPayloadForSlot(1));
        self::assertSame(['n' => 1], $history->childWorkflowInputForSlot(0));
        self::assertSame(['n' => 2], $history->childWorkflowInputForSlot(1));
    }

    public function testASlotNobodyScheduledHasNoPayload(): void
    {
        // Null, et non `[]` : « rien enregistré » désarme la garde, « planifié sans argument » non.
        $history = TemporalExecutionHistory::fromEvents([]);

        self::assertNull($history->activityPayloadForSlot(0));
        self::assertNull($history->nexusOperationPayloadForSlot(0));
        self::assertNull($history->childWorkflowInputForSlot(0));
    }

    public function testAnEmptyPayloadIsRecordedAsEmptyNotAsAbsent(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->nexusScheduled(5, 'paiements', 'facturation', 'ping', []),
        ]);

        self::assertSame([], $history->nexusOperationPayloadForSlot(0));
    }

    public function testTheGuardRefusesANexusOperationWhosePayloadChanged(): void
    {
        // Le câblage, pas seulement la lecture : c'est ici que Nexus mérite le plus la garde —
        // une activité replanifiée retombe sur un worker à soi, une opération Nexus part chez un
        // tiers, où le doublon est le sien.
        $context = new ExecutionContext(
            'exec-nexus',
            TemporalExecutionHistory::fromEvents([
                $this->nexusScheduled(5, 'paiements', 'facturation', 'encaisser', ['amount' => 90]),
            ]),
            $this->createStub(WorkflowCommandBufferInterface::class),
        );

        try {
            $context->nexusOperation(
                NexusEndpoint::named('paiements'),
                NexusService::named('facturation'),
                NexusOperationName::named('encaisser'),
                ['amount' => 120],
            );
            self::fail('La divergence de charge aurait dû être refusée.');
        } catch (WorkflowTaskFailure $refusal) {
            $message = $refusal->getMessage();
        }

        self::assertStringContainsString('Nexus operation slot 0', $message);
        self::assertStringContainsString('"paiements/facturation/encaisser" is still the same Nexus operation', $message);
    }

    public function testAFaithfulNexusReplayIsNotRefused(): void
    {
        $context = new ExecutionContext(
            'exec-nexus',
            TemporalExecutionHistory::fromEvents([
                $this->nexusScheduled(5, 'paiements', 'facturation', 'encaisser', ['amount' => 90]),
            ]),
            $this->createStub(WorkflowCommandBufferInterface::class),
        );

        $awaitable = $context->nexusOperation(
            NexusEndpoint::named('paiements'),
            NexusService::named('facturation'),
            NexusOperationName::named('encaisser'),
            ['amount' => 90],
        );

        self::assertNotNull($awaitable);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function activityScheduled(int $eventId, string $activityId, string $name, array $payload): HistoryEvent
    {
        $attrs = new ActivityTaskScheduledEventAttributes();
        $attrs->setActivityId($activityId);
        $attrs->setActivityType(new \Temporal\Api\Common\V1\ActivityType(['name' => $name]));
        $attrs->setInput(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode([
            'executionId' => 'exec-1',
            'activityId' => $activityId,
            'activityName' => $name,
            'payload' => $payload,
            'metadata' => [],
        ])));

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);
        $event->setEventId($eventId);
        $event->setActivityTaskScheduledEventAttributes($attrs);

        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function nexusScheduled(int $eventId, string $endpoint, string $service, string $operation, array $payload): HistoryEvent
    {
        $attrs = new NexusOperationScheduledEventAttributes();
        $attrs->setEndpoint($endpoint);
        $attrs->setService($service);
        $attrs->setOperation($operation);
        // Nu, comme le tampon l'écrit.
        $attrs->setInput(JsonPlainPayload::encode($payload));

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED);
        $event->setEventId($eventId);
        $event->setNexusOperationScheduledEventAttributes($attrs);

        return $event;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function childScheduled(string $workflowId, string $type, array $input): HistoryEvent
    {
        $attrs = new StartChildWorkflowExecutionInitiatedEventAttributes();
        $attrs->setWorkflowId($workflowId);
        $attrs->setWorkflowType(new WorkflowType(['name' => $type]));
        $attrs->setInput(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($input)));

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_INITIATED);
        $event->setStartChildWorkflowExecutionInitiatedEventAttributes($attrs);

        return $event;
    }
}
