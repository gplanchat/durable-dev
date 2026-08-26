<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionSignaledEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionUpdateAcceptedEventAttributes;
use Temporal\Api\Update\V1\Input as UpdateInput;
use Temporal\Api\Update\V1\Meta as UpdateMeta;
use Temporal\Api\Update\V1\Request as UpdateRequest;

/**
 * Parité de dispatch entre les deux backends (tâche 7.1).
 *
 * Le piège est structurel, pas cosmétique : le backend in-memory énumère un flux unique, où
 * signaux et updates sont déjà entremêlés ; le backend Temporal les tient dans **deux tableaux
 * séparés**. Les concaténer ferait passer tous les signaux avant tous les updates, quel que soit
 * l'ordre réel du journal — une divergence silencieuse, et exactement celle que ce change a
 * supprimée pour les rangs de signaux.
 *
 * Le même journal doit donc rendre la même suite de messages des deux côtés.
 */
#[RequiresPhpExtension('grpc')]
final class HandlerDispatchParityTest extends TestCase
{
    public function testBothBackendsReadTheSameMessagesInTheSameOrder(): void
    {
        $expected = [
            ['kind' => 'signal', 'name' => 'tick', 'payload' => ['n' => 1]],
            ['kind' => 'update', 'name' => 'approve', 'payload' => ['by' => 'alice']],
            ['kind' => 'signal', 'name' => 'tick', 'payload' => ['n' => 2]],
        ];

        self::assertSame($expected, self::drain($this->inMemoryHistory()), 'backend in-memory');
        self::assertSame($expected, self::drain($this->temporalHistory()), 'backend Temporal');
    }

    public function testBothBackendsSituateADeadlineAgainstTheSameMessages(): void
    {
        // La comparaison qui tranche un verdict d'échéance : le message est-il enregistré avant
        // ou après le tir ? Les positions ne sont pas les mêmes d'un backend à l'autre — rang de
        // flux ici, eventId là — et ce n'est pas ce qui compte. Ce qui compte est le classement.
        foreach ([$this->inMemoryHistory(), $this->temporalHistory()] as $history) {
            $firedAt = $history->timerCompletionPosition('timer-a');
            self::assertNotNull($firedAt);

            $verdicts = [];
            for ($i = 0; null !== ($message = $history->messageAt($i)); ++$i) {
                $verdicts[] = $message['position'] < $firedAt ? 'avant' : 'après';
            }

            self::assertSame(['avant', 'avant', 'après'], $verdicts);
        }
    }

    /**
     * @return list<array{kind: string, name: string, payload: array<string, mixed>}>
     */
    private static function drain(WorkflowHistorySourceInterface $history): array
    {
        $messages = [];
        for ($i = 0; null !== ($message = $history->messageAt($i)); ++$i) {
            unset($message['position']);
            $messages[] = $message;
        }

        return $messages;
    }

    private function inMemoryHistory(): EventStoreHistorySource
    {
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted('parity-1', []));
        $store->append(new WorkflowSignalReceived('parity-1', 'tick', ['n' => 1]));
        $store->append(new WorkflowUpdateHandled('parity-1', 'approve', ['by' => 'alice'], null));
        $store->append(new \Gplanchat\Durable\Event\TimerScheduled('parity-1', 'timer-a', 0.0));
        $store->append(new \Gplanchat\Durable\Event\TimerCompleted('parity-1', 'timer-a'));
        $store->append(new WorkflowSignalReceived('parity-1', 'tick', ['n' => 2]));

        return new EventStoreHistorySource($store, 'parity-1');
    }

    private function temporalHistory(): TemporalExecutionHistory
    {
        return TemporalExecutionHistory::fromEvents([
            self::event(1, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED),
            self::signal(2, 'tick', ['n' => 1]),
            self::updateAccepted(3, 'approve', ['by' => 'alice']),
            self::timerStarted(4, 'timer-a'),
            self::timerFired(5, 4),
            self::signal(6, 'tick', ['n' => 2]),
        ]);
    }

    private static function event(int $id, int $type): HistoryEvent
    {
        $event = new HistoryEvent();
        $event->setEventId($id);
        $event->setEventType($type);

        return $event;
    }

    private static function signal(int $id, string $name, mixed $payload): HistoryEvent
    {
        $event = self::event($id, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED);
        $attributes = new WorkflowExecutionSignaledEventAttributes();
        $attributes->setSignalName($name);
        $payloads = new Payloads();
        $payloads->setPayloads([JsonPlainPayload::encode($payload)]);
        $attributes->setInput($payloads);
        $event->setWorkflowExecutionSignaledEventAttributes($attributes);

        return $event;
    }

    private static function updateAccepted(int $id, string $name, mixed $args): HistoryEvent
    {
        $event = self::event($id, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ACCEPTED);

        $input = new UpdateInput();
        $input->setName($name);
        $input->setArgs(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($args)));
        $request = new UpdateRequest();
        $request->setMeta(new UpdateMeta(['update_id' => 'upd-' . $id, 'identity' => 'test']));
        $request->setInput($input);

        $attributes = new WorkflowExecutionUpdateAcceptedEventAttributes();
        $attributes->setAcceptedRequest($request);
        $event->setWorkflowExecutionUpdateAcceptedEventAttributes($attributes);

        return $event;
    }

    private static function timerStarted(int $id, string $timerId): HistoryEvent
    {
        $event = self::event($id, EventType::EVENT_TYPE_TIMER_STARTED);
        $attributes = new \Temporal\Api\History\V1\TimerStartedEventAttributes();
        $attributes->setTimerId($timerId);
        $event->setTimerStartedEventAttributes($attributes);

        return $event;
    }

    private static function timerFired(int $id, int $startedEventId): HistoryEvent
    {
        $event = self::event($id, EventType::EVENT_TYPE_TIMER_FIRED);
        $attributes = new \Temporal\Api\History\V1\TimerFiredEventAttributes();
        $attributes->setStartedEventId($startedEventId);
        $event->setTimerFiredEventAttributes($attributes);

        return $event;
    }
}
