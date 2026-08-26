<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Wire;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\CronSchedule;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\SearchAttributes;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowIdReusePolicy;
use Gplanchat\Durable\WorkflowNamespace;
use Gplanchat\Durable\WorkflowTimeouts;
use PHPUnit\Framework\TestCase;

/**
 * Épingle la forme de fil, indépendamment des signatures de ports.
 *
 * Elle voyage dans le journal in-memory et dans l'historique Temporal des exécutions en cours :
 * la déplacer casserait leur rejeu, en silence. Les tableaux ci-dessous sont relevés sur le code
 * tel qu'il est aujourd'hui ; un refactor de frontière ne doit pas les faire bouger d'un octet.
 *
 * Ce test n'est pas là pour décrire un comportement souhaitable — il est là pour interdire un
 * changement. Si vous devez le modifier, c'est une migration de données, pas un refactor.
 *
 * @see openspec/changes/value-objects-through-ports
 */
final class WireFormatPinTest extends TestCase
{
    public function testActivityOptionsWireForm(): void
    {
        $options = new ActivityOptions(
            RetryLimit::ofAttempts(3),
            initialInterval: Duration::seconds(2),
            backoffCoefficient: 2.5,
            maximumInterval: Duration::seconds(60),
            nonRetryableExceptions: ['App\\Boom'],
            taskQueue: TaskQueue::named('payments'),
            activityId: 'act-1',
            timeouts: new ActivityTimeouts(
                Duration::seconds(5),
                Duration::seconds(30),
                Duration::minutes(10),
                Duration::seconds(15),
            ),
            summary: 'Charge',
        );

        self::assertSame([
            'activity_options' => [
                'max_attempts' => 3,
                'initial_interval_seconds' => 2.0,
                'backoff_coefficient' => 2.5,
                'non_retryable_exceptions' => ['App\\Boom'],
                'cancellation_type' => 0,
                'maximum_interval_seconds' => 60.0,
                'task_queue' => 'payments',
                'activity_id' => 'act-1',
                'schedule_to_start_timeout_seconds' => 5.0,
                'start_to_close_timeout_seconds' => 30.0,
                'schedule_to_close_timeout_seconds' => 600.0,
                'heartbeat_timeout_seconds' => 15.0,
                'summary' => 'Charge',
            ],
        ], $options->toMetadata());
    }

    public function testChildWorkflowOptionsWireForm(): void
    {
        $options = new ChildWorkflowOptions(
            workflowId: 'child-1',
            parentClosePolicy: ParentClosePolicy::Abandon,
            namespace: WorkflowNamespace::named('billing'),
            taskQueue: TaskQueue::named('children'),
            timeouts: new WorkflowTimeouts(Duration::hours(1), Duration::minutes(10), Duration::seconds(10)),
            cronSchedule: CronSchedule::parse('0 9 * * 1-5'),
            memo: ['k' => 'v'],
            searchAttributes: SearchAttributes::none()->keyword('OrderId', 'ORD-1')->int('Amount', 42),
            workflowIdReusePolicy: WorkflowIdReusePolicy::RejectDuplicate,
            staticSummary: 'S',
            staticDetails: 'D',
        );

        self::assertSame([
            'namespace' => 'billing',
            'task_queue' => 'children',
            'workflow_execution_timeout_seconds' => 3600.0,
            'workflow_run_timeout_seconds' => 600.0,
            'workflow_task_timeout_seconds' => 10.0,
            'cron_schedule' => '0 9 * * 1-5',
            'memo' => ['k' => 'v'],
            'search_attributes' => [
                'OrderId' => ['type' => 'Keyword', 'value' => 'ORD-1'],
                'Amount' => ['type' => 'Int', 'value' => 42],
            ],
            'workflow_id_reuse_policy' => 'reject_duplicate',
            'static_summary' => 'S',
            'static_details' => 'D',
        ], $options->toSchedulingMetadata());
    }

    public function testOptionsRoundTripFromTheirOwnWireForm(): void
    {
        $options = new ActivityOptions(
            RetryLimit::ofAttempts(4),
            taskQueue: TaskQueue::named('q'),
            timeouts: ActivityTimeouts::attempt(Duration::seconds(45)),
        );
        $decoded = ActivityOptions::fromMetadata($options->toMetadata());

        self::assertNotNull($decoded);
        self::assertSame($options->toMetadata(), $decoded->toMetadata(), 'un aller-retour ne doit rien perdre');
    }

    /**
     * Une activité planifiée telle que le journal la porte aujourd'hui.
     */
    public function testScheduledActivityJournalRecord(): void
    {
        $event = new ActivityScheduled(
            'exec-1',
            'act-1',
            'charge',
            ['orderId' => 'ORD-1'],
            (new ActivityOptions(RetryLimit::once(), timeouts: ActivityTimeouts::attempt(Duration::seconds(30))))->toMetadata(),
        );

        $record = EventDataMapper::fromDomainEvent($event);

        self::assertSame([
            'execution_id' => 'exec-1',
            'event_type' => ActivityScheduled::class,
            'payload' => [
                'activityId' => 'act-1',
                'activityName' => 'charge',
                'payload' => ['orderId' => 'ORD-1'],
                'metadata' => [
                    'activity_options' => [
                        'max_attempts' => 1,
                        'initial_interval_seconds' => 1.0,
                        'backoff_coefficient' => 2.0,
                        'non_retryable_exceptions' => [],
                        'cancellation_type' => 0,
                        'start_to_close_timeout_seconds' => 30.0,
                    ],
                ],
            ],
        ], $record);

        self::assertEquals($event->payload(), EventDataMapper::toDomainEvent($record)->payload());
    }

    /**
     * Ce qu'une planification réelle écrit : les options, plus l'horodatage de mise en file dont
     * ActivityMessageProcessor se sert pour les timeouts schedule-to-*.
     *
     * L'horodatage vient de l'horloge du **backend**, pas d'un microtime() lu par le cœur : un
     * moteur de rejeu ne consulte pas l'horloge murale.
     */
    public function testScheduledActivityCarriesTheBackendClock(): void
    {
        $store = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $context = new ExecutionContext(
            'exec-1',
            new EventStoreHistorySource($store, 'exec-1'),
            new EventStoreCommandBuffer($store, $transport, 'exec-1', static fn(): float => 1_700_000_000.0),
        );

        $context->activity('charge', ['o' => 1], new ActivityOptions(
            RetryLimit::once(),
            taskQueue: TaskQueue::named('q'),
            timeouts: ActivityTimeouts::attempt(Duration::seconds(30)),
        ));

        $scheduled = null;
        foreach ($store->readStream('exec-1') as $event) {
            if ($event instanceof ActivityScheduled) {
                $scheduled = $event;
            }
        }

        self::assertNotNull($scheduled);
        self::assertSame([
            'activity_options' => [
                'max_attempts' => 1,
                'initial_interval_seconds' => 1.0,
                'backoff_coefficient' => 2.0,
                'non_retryable_exceptions' => [],
                'cancellation_type' => 0,
                'task_queue' => 'q',
                'start_to_close_timeout_seconds' => 30.0,
            ],
            'queued_at' => 1_700_000_000.0,
            'first_queued_at' => 1_700_000_000.0,
        ], $scheduled->payload()['metadata']);
    }

    /**
     * Un minuteur porte une échéance absolue dans le journal. Le port qui la transporte peut
     * changer ; l'événement enregistré, non.
     */
    public function testScheduledTimerJournalRecord(): void
    {
        $record = EventDataMapper::fromDomainEvent(new TimerScheduled('exec-1', 'timer-1', 1_700_000_000.5, 'wait'));

        self::assertSame([
            'execution_id' => 'exec-1',
            'event_type' => TimerScheduled::class,
            'payload' => [
                'timerId' => 'timer-1',
                'scheduledAt' => 1_700_000_000.5,
                'summary' => 'wait',
            ],
        ], $record);
    }
}
