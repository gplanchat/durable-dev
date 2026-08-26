<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Integration;

use Google\Protobuf\Timestamp;
use Gplanchat\Durable\Plugin\Dashboard\TemporalEventsDashboardDataProvider;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionUpdateAcceptedEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionUpdateCompletedEventAttributes;
use Temporal\Api\Update\V1\Input;
use Temporal\Api\Update\V1\Meta;
use Temporal\Api\Update\V1\Request;

final class TemporalDashboardTimelineGroupingTest extends TestCase
{
    public function testActivityTimelineUsesActivityTypeNameInsteadOfUuid(): void
    {
        $provider = new TemporalEventsDashboardDataProvider();

        $init = new \ReflectionMethod(TemporalEventsDashboardDataProvider::class, 'initTimelineRaw');
        $init->setAccessible(true);
        /** @var array<string, mixed> $timelineRaw */
        $timelineRaw = $init->invoke($provider);

        $collect = new \ReflectionMethod(TemporalEventsDashboardDataProvider::class, 'collectTimelineEvent');
        $collect->setAccessible(true);

        $event = new HistoryEvent();
        $event->setEventId(1001);
        $event->setEventType(EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);
        $event->setEventTime($this->timestamp(1_500));

        $attributes = new ActivityTaskScheduledEventAttributes();
        $attributes->setActivityId('42f1dd58-d7f8-4f84-9a09-7f8937493f3a');
        $attributes->setActivityType(new ActivityType(['name' => 'SendWelcomeEmail']));
        $event->setActivityTaskScheduledEventAttributes($attributes);

        $timestamp = (float) $event->getEventTime()->getSeconds();
        $timelineRaw['min'] = $timestamp;
        $timelineRaw['max'] = $timestamp;

        $eventType = EventType::name($event->getEventType());
        $args = [&$timelineRaw, $event, $eventType, $timestamp];
        $collect->invokeArgs($provider, $args);

        $finalize = new \ReflectionMethod(TemporalEventsDashboardDataProvider::class, 'finalizeTimeline');
        $finalize->setAccessible(true);
        /** @var array{lanes:list<array{kind:string,label:string}>} $timeline */
        $timeline = $finalize->invoke($provider, $timelineRaw, 'all', ['activity'], false);

        self::assertCount(1, $timeline['lanes']);
        self::assertSame('activity', $timeline['lanes'][0]['kind']);
        self::assertSame('Activity: SendWelcomeEmail', $timeline['lanes'][0]['label']);
    }

    public function testActivityTimelineFallsBackToActivityIdWhenTypeNameIsMissing(): void
    {
        $provider = new TemporalEventsDashboardDataProvider();

        $init = new \ReflectionMethod(TemporalEventsDashboardDataProvider::class, 'initTimelineRaw');
        $init->setAccessible(true);
        /** @var array<string, mixed> $timelineRaw */
        $timelineRaw = $init->invoke($provider);

        $collect = new \ReflectionMethod(TemporalEventsDashboardDataProvider::class, 'collectTimelineEvent');
        $collect->setAccessible(true);

        $event = new HistoryEvent();
        $event->setEventId(1002);
        $event->setEventType(EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);
        $event->setEventTime($this->timestamp(1_600));

        $attributes = new ActivityTaskScheduledEventAttributes();
        $attributes->setActivityId('activity-business-id');
        $event->setActivityTaskScheduledEventAttributes($attributes);

        $timestamp = (float) $event->getEventTime()->getSeconds();
        $timelineRaw['min'] = $timestamp;
        $timelineRaw['max'] = $timestamp;

        $eventType = EventType::name($event->getEventType());
        $args = [&$timelineRaw, $event, $eventType, $timestamp];
        $collect->invokeArgs($provider, $args);

        $finalize = new \ReflectionMethod(TemporalEventsDashboardDataProvider::class, 'finalizeTimeline');
        $finalize->setAccessible(true);
        /** @var array{lanes:list<array{kind:string,label:string}>} $timeline */
        $timeline = $finalize->invoke($provider, $timelineRaw, 'all', ['activity'], false);

        self::assertCount(1, $timeline['lanes']);
        self::assertSame('activity', $timeline['lanes'][0]['kind']);
        self::assertSame('Activity: activity-business-id', $timeline['lanes'][0]['label']);
    }

    public function testQueryTimelineUsesShortReadableLabel(): void
    {
        $provider = new TemporalEventsDashboardDataProvider();
        $toShortQueryLabel = new \ReflectionMethod(TemporalEventsDashboardDataProvider::class, 'toShortQueryLabel');
        $toShortQueryLabel->setAccessible(true);

        $label = $toShortQueryLabel->invoke($provider, 'EVENT_TYPE_WORKFLOW_EXECUTION_QUERY_COMPLETED', 1003);

        self::assertSame('Query: Completed', $label);
    }

    public function testQueryTimelineFallsBackToEventIdWhenTypeNameIsEmpty(): void
    {
        $provider = new TemporalEventsDashboardDataProvider();
        $toShortQueryLabel = new \ReflectionMethod(TemporalEventsDashboardDataProvider::class, 'toShortQueryLabel');
        $toShortQueryLabel->setAccessible(true);

        $label = $toShortQueryLabel->invoke($provider, '', 4242);

        self::assertSame('query-4242', $label);
    }

    public function testUpdateTimelineShortensTechnicalEnumLabel(): void
    {
        $provider = new TemporalEventsDashboardDataProvider();
        $toShortUpdateLabel = new \ReflectionMethod(TemporalEventsDashboardDataProvider::class, 'toShortUpdateLabel');
        $toShortUpdateLabel->setAccessible(true);

        $label = $toShortUpdateLabel->invoke($provider, 'EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ACCEPTED', 77);

        self::assertSame('WORKFLOW EXECUTION UPDATE ACCEPTED', $label);
    }

    public function testUpdateEventsAreGroupedByProtocolInstanceId(): void
    {
        $provider = new TemporalEventsDashboardDataProvider();

        $init = new \ReflectionMethod(TemporalEventsDashboardDataProvider::class, 'initTimelineRaw');
        $init->setAccessible(true);
        /** @var array<string, mixed> $timelineRaw */
        $timelineRaw = $init->invoke($provider);

        $collect = new \ReflectionMethod(TemporalEventsDashboardDataProvider::class, 'collectTimelineEvent');
        $collect->setAccessible(true);

        $events = [
            $this->acceptedEvent(42, 1_000, 'pid-a', 'orderUpdate'),
            $this->completedEvent(43, 1_010, 42, 'order-update-id'),
            $this->acceptedEvent(44, 1_020, 'pid-a', 'orderUpdate'),
            $this->completedEvent(45, 1_030, 44, 'order-update-id'),
            $this->acceptedEvent(46, 1_005, 'pid-b', 'billingUpdate'),
            $this->completedEvent(47, 1_025, 46, 'billing-update-id'),
        ];

        foreach ($events as $event) {
            $timestamp = (float) $event->getEventTime()->getSeconds();
            $timelineRaw['min'] = null === $timelineRaw['min'] ? $timestamp : min($timelineRaw['min'], $timestamp);
            $timelineRaw['max'] = null === $timelineRaw['max'] ? $timestamp : max($timelineRaw['max'], $timestamp);

            $eventType = EventType::name($event->getEventType());
            $args = [&$timelineRaw, $event, $eventType, $timestamp];
            $collect->invokeArgs($provider, $args);
        }

        $finalize = new \ReflectionMethod(TemporalEventsDashboardDataProvider::class, 'finalizeTimeline');
        $finalize->setAccessible(true);
        /** @var array{lanes:list<array{kind:string,label:string}>} $timeline */
        $timeline = $finalize->invoke($provider, $timelineRaw, 'all', ['update'], false);

        self::assertCount(2, $timeline['lanes'], 'Expected one lane per protocol_instance_id.');
        self::assertSame('update', $timeline['lanes'][0]['kind']);
        self::assertSame('update', $timeline['lanes'][1]['kind']);
        self::assertStringContainsString('Update: orderUpdate', $timeline['lanes'][0]['label'] . $timeline['lanes'][1]['label']);
        self::assertStringContainsString('Update: billingUpdate', $timeline['lanes'][0]['label'] . $timeline['lanes'][1]['label']);
    }

    private function acceptedEvent(int $eventId, int $seconds, string $protocolId, string $updateName): HistoryEvent
    {
        $input = new Input();
        $input->setName($updateName);

        $request = new Request();
        $request->setInput($input);

        $attributes = new WorkflowExecutionUpdateAcceptedEventAttributes();
        $attributes->setProtocolInstanceId($protocolId);
        $attributes->setAcceptedRequest($request);

        $event = new HistoryEvent();
        $event->setEventId($eventId);
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ACCEPTED);
        $event->setEventTime($this->timestamp($seconds));
        $event->setWorkflowExecutionUpdateAcceptedEventAttributes($attributes);

        return $event;
    }

    private function completedEvent(int $eventId, int $seconds, int $acceptedEventId, string $updateId): HistoryEvent
    {
        $meta = new Meta();
        $meta->setUpdateId($updateId);

        $attributes = new WorkflowExecutionUpdateCompletedEventAttributes();
        $attributes->setAcceptedEventId($acceptedEventId);
        $attributes->setMeta($meta);

        $event = new HistoryEvent();
        $event->setEventId($eventId);
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_COMPLETED);
        $event->setEventTime($this->timestamp($seconds));
        $event->setWorkflowExecutionUpdateCompletedEventAttributes($attributes);

        return $event;
    }

    private function timestamp(int $seconds): Timestamp
    {
        $timestamp = new Timestamp();
        $timestamp->setSeconds($seconds);
        $timestamp->setNanos(0);

        return $timestamp;
    }
}
