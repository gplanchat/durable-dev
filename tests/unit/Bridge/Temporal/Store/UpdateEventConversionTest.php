<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Store;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Store\TemporalEventConverter;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Exception\DurableUpdateFailedException;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Failure\V1\ApplicationFailureInfo;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionUpdateAcceptedEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionUpdateCompletedEventAttributes;
use Temporal\Api\Update\V1\Input;
use Temporal\Api\Update\V1\Meta;
use Temporal\Api\Update\V1\Outcome;
use Temporal\Api\Update\V1\Request;

/**
 * An accepted update and its completion become one WorkflowUpdateHandled, at the completion (#1008).
 */
final class UpdateEventConversionTest extends TestCase
{
    public function testAnAcceptedThenCompletedUpdateCarriesItsNameArgumentsAndResult(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));

        self::assertNull($converter->convert($this->accepted(5, 'upd-a', 'approve', ['by' => 'ann'])));
        $read = $converter->convert($this->completed(7, 'upd-a', 5, $this->success('ok')));

        self::assertInstanceOf(WorkflowUpdateHandled::class, $read);
        self::assertSame('approve', $read->updateName());
        self::assertSame(['by' => 'ann'], $read->arguments());
        self::assertSame('ok', $read->result());
        self::assertNull($read->failure());
    }

    public function testAFailedUpdateCarriesTheFailureAndNoResult(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));
        $converter->convert($this->accepted(5, 'upd-a', 'approve'));

        $read = $converter->convert($this->completed(7, 'upd-a', 5, (new Outcome())->setFailure((new Failure())->setMessage('nope'))));

        self::assertInstanceOf(WorkflowUpdateHandled::class, $read);
        self::assertNull($read->result());
        self::assertSame(DurableUpdateFailedException::class, $read->failure()?->class);
        self::assertSame('nope', $read->failure()->message);
    }

    public function testAFailedUpdateWithAnApplicationFailureTypeKeepsThatClass(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));
        $converter->convert($this->accepted(5, 'upd-a', 'approve'));
        $failure = (new Failure())->setMessage('nope')->setApplicationFailureInfo((new ApplicationFailureInfo())->setType(\DomainException::class));

        $read = $converter->convert($this->completed(7, 'upd-a', 5, (new Outcome())->setFailure($failure)));

        self::assertInstanceOf(WorkflowUpdateHandled::class, $read);
        self::assertSame(\DomainException::class, $read->failure()?->class);
    }

    public function testTwoInterleavedUpdatesArePairedByUpdateIdNotByPosition(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));
        $converter->convert($this->accepted(5, 'upd-a', 'approve', ['n' => 1]));
        $converter->convert($this->accepted(6, 'upd-b', 'reject', ['n' => 2]));

        $first = $converter->convert($this->completed(8, 'upd-a', 5, $this->success('A')));
        $second = $converter->convert($this->completed(9, 'upd-b', 6, $this->success('B')));

        self::assertInstanceOf(WorkflowUpdateHandled::class, $first);
        self::assertInstanceOf(WorkflowUpdateHandled::class, $second);
        self::assertSame(['approve', ['n' => 1], 'A'], [$first->updateName(), $first->arguments(), $first->result()]);
        self::assertSame(['reject', ['n' => 2], 'B'], [$second->updateName(), $second->arguments(), $second->result()]);

        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));
        $converter->convert($this->accepted(5, 'upd-a', 'approve'));
        $converter->convert($this->accepted(6, 'upd-b', 'reject'));

        $reversed = $converter->convert($this->completed(8, 'upd-b', 6, $this->success('B')));

        self::assertInstanceOf(WorkflowUpdateHandled::class, $reversed);
        self::assertSame('reject', $reversed->updateName());
    }

    public function testAnAcceptedUpdateThatHasNotCompletedEmitsNothing(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));

        self::assertNull($converter->convert($this->accepted(5, 'upd-a', 'approve', ['by' => 'ann'])));
        self::assertNull($converter->convert($this->accepted(6, 'upd-b', 'reject')));
    }

    public function testACompletionWithoutAnUpdateIdFindsItsUpdateByTheAcceptedEventId(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));
        $converter->convert($this->accepted(5, 'upd-a', 'approve'));
        $converter->convert($this->accepted(6, 'upd-b', 'reject'));

        $read = $converter->convert($this->completed(8, '', 6, $this->success('B')));

        self::assertInstanceOf(WorkflowUpdateHandled::class, $read);
        self::assertSame('reject', $read->updateName());
    }

    public function testTwoAcceptedUpdatesWithoutAnIdDoNotOverwriteEachOther(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));
        $converter->convert($this->accepted(5, '', 'approve'));
        $converter->convert($this->accepted(6, '', 'reject'));

        $read = $converter->convert($this->completed(8, '', 5, $this->success('A')));

        self::assertInstanceOf(WorkflowUpdateHandled::class, $read);
        self::assertSame('approve', $read->updateName());
    }

    public function testACompletionOfAnUpdateNeverAcceptedEmitsNothing(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));

        self::assertNull($converter->convert($this->completed(8, 'upd-x', 5, $this->success('A'))));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function accepted(int $eventId, string $updateId, string $name, array $arguments = []): HistoryEvent
    {
        $input = new Input(['name' => $name]);
        if ([] !== $arguments) {
            $input->setArgs((new Payloads())->setPayloads([JsonPlainPayload::encode($arguments)]));
        }
        $request = new Request();
        $request->setMeta(new Meta(['update_id' => $updateId]));
        $request->setInput($input);

        $attrs = new WorkflowExecutionUpdateAcceptedEventAttributes();
        $attrs->setAcceptedRequest($request);

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ACCEPTED);
        $event->setEventId($eventId);
        $event->setWorkflowExecutionUpdateAcceptedEventAttributes($attrs);

        return $event;
    }

    private function completed(int $eventId, string $updateId, int $acceptedEventId, Outcome $outcome): HistoryEvent
    {
        $attrs = new WorkflowExecutionUpdateCompletedEventAttributes();
        if ('' !== $updateId) {
            $attrs->setMeta(new Meta(['update_id' => $updateId]));
        }
        $attrs->setAcceptedEventId($acceptedEventId);
        $attrs->setOutcome($outcome);

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_COMPLETED);
        $event->setEventId($eventId);
        $event->setWorkflowExecutionUpdateCompletedEventAttributes($attrs);

        return $event;
    }

    private function success(mixed $result): Outcome
    {
        return (new Outcome())->setSuccess((new Payloads())->setPayloads([JsonPlainPayload::encode($result)]));
    }
}
