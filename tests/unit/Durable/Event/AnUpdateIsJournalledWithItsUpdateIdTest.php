<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Event;

use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowUpdateHandler;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Workflow\PendingUpdate;
use Gplanchat\Durable\WorkflowEnvironment;
use integration\Durable\Support\CallbackWorkflowResumeDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The journal names the update an outcome belongs to, so a caller reads its own result when two
 * identical updates run at once (workflow-repository, task 1.6).
 */
final class AnUpdateIsJournalledWithItsUpdateIdTest extends TestCase
{
    public function testTheUpdateIdSurvivesTheJournalRecord(): void
    {
        $event = new WorkflowUpdateHandled(ExecutionId::fromString('exec-1'), 'amend', ['n' => 1], 'ok', null, 'upd-1');

        $record = EventDataMapper::fromDomainEvent($event);
        $read = EventDataMapper::toDomainEvent($record);

        self::assertSame('upd-1', $record['payload']['updateId']);
        self::assertInstanceOf(WorkflowUpdateHandled::class, $read);
        self::assertSame('upd-1', $read->updateId());
    }

    public function testAnEventWrittenWithoutAnIdReplaysUnchangedAndMatchesNoClient(): void
    {
        $written = [
            'execution_id' => 'exec-1',
            'event_type' => WorkflowUpdateHandled::class,
            'payload' => ['updateName' => 'amend', 'arguments' => ['n' => 1], 'result' => 'ok', 'failure' => null],
        ];

        $read = EventDataMapper::toDomainEvent($written);

        self::assertInstanceOf(WorkflowUpdateHandled::class, $read);
        self::assertNull($read->updateId());
        self::assertSame($written, EventDataMapper::fromDomainEvent($read), 'the record is written back as it was read');
    }

    public function testThePassJournalsTheIdOfThePendingUpdate(): void
    {
        $store = new InMemoryEventStore();
        $engine = new ExecutionEngine($store, new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true));
        $store->append(new ExecutionStarted(ExecutionId::fromString('exec-1'), []));

        $engine->resume(ExecutionId::fromString('exec-1'), static function (WorkflowEnvironment $wf): string {
            $done = false;
            $wf->onUpdate('amend', static function () use (&$done): string {
                $done = true;

                return 'ok';
            });
            $wf->await(static function () use (&$done): bool {
                return $done;
            });

            return 'done';
        }, null, [new PendingUpdate('amend', [], 'upd-1')]);

        $handled = array_values(array_filter(iterator_to_array($store->readStream(ExecutionId::fromString('exec-1')), false), static fn(object $e): bool => $e instanceof WorkflowUpdateHandled));
        self::assertCount(1, $handled);
        self::assertSame('upd-1', $handled[0]->updateId());
    }

    public function testTheDeliveryHandlerPassesTheMessagesUpdateIdOn(): void
    {
        $sent = [];
        $dispatcher = new CallbackWorkflowResumeDispatcher(static function (string $id, array $updates) use (&$sent): void {
            $sent = $updates;
        });

        (new DeliverWorkflowUpdateHandler($dispatcher, new InMemoryEventStore()))(new DeliverWorkflowUpdateMessage('exec-1', 'amend', [], 'upd-1'));

        self::assertSame([['name' => 'amend', 'arguments' => [], 'updateId' => 'upd-1']], $sent);
    }
}
