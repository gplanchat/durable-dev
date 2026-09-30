<?php

declare(strict_types=1);

/*
 * What the payload walk of PayloadCodecWorkflowServiceClient costs on a large history page (#764):
 * a GetWorkflowExecutionHistory response of 1,200 events, decoded from its wire bytes as a
 * transport would, read bare and through the codec client with a codec that changes nothing.
 *
 *     php tests/unit/Bridge/Temporal/Codec/payload_walk_benchmark.php [rounds]
 *
 * Prints the median time of each and the walk's share of the decode. A script, not a test: a
 * timing assertion would fail on a busy CI runner and prove nothing.
 */

require \dirname(__DIR__, 5) . '/vendor/autoload.php';

use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\AbstractWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\Codec\PayloadCodecInterface;
use Gplanchat\Bridge\Temporal\Codec\PayloadCodecWorkflowServiceClient;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Common\V1\Header;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\SearchAttributes;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\ActivityTaskCompletedEventAttributes;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\MarkerRecordedEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionSignaledEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionStartedEventAttributes;
use Temporal\Api\History\V1\WorkflowTaskCompletedEventAttributes;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryRequest;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;

$rounds = max(3, (int) ($argv[1] ?? 15));

$payload = static fn(string $data): Payload => new Payload(['metadata' => ['encoding' => 'json/plain'], 'data' => $data]);
$payloads = static fn(string $data): Payloads => new Payloads(['payloads' => [$payload($data)]]);
$json = json_encode(['orderId' => 'order-42', 'lines' => array_fill(0, 5, ['sku' => 'SKU-123', 'quantity' => 2, 'price' => 1999])], \JSON_THROW_ON_ERROR);

$events = [new HistoryEvent([
    'event_id' => 1,
    'event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED,
    'workflow_execution_started_event_attributes' => new WorkflowExecutionStartedEventAttributes([
        'input' => $payloads($json),
        'header' => new Header(['fields' => ['trace' => $payload('abc')]]),
        'search_attributes' => new SearchAttributes(['indexed_fields' => ['DurableExecutionId' => $payload('"order-42"')]]),
    ]),
])];
// One activity round trip per step, a marker and a signal now and then: what a Durable run records.
for ($id = 2; \count($events) < 1_200; ++$id) {
    $events[] = match ($id % 5) {
        0 => new HistoryEvent(['event_id' => $id, 'event_type' => EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED, 'activity_task_scheduled_event_attributes' => new ActivityTaskScheduledEventAttributes([
            'activity_id' => (string) $id,
            'activity_type' => new ActivityType(['name' => 'App\Activity\ReserveStock']),
            'input' => $payloads($json),
            'header' => new Header(['fields' => ['trace' => $payload('abc')]]),
        ])]),
        1 => new HistoryEvent(['event_id' => $id, 'event_type' => EventType::EVENT_TYPE_ACTIVITY_TASK_COMPLETED, 'activity_task_completed_event_attributes' => new ActivityTaskCompletedEventAttributes([
            'result' => $payloads($json),
            'scheduled_event_id' => $id - 1,
        ])]),
        2 => new HistoryEvent(['event_id' => $id, 'event_type' => EventType::EVENT_TYPE_WORKFLOW_TASK_COMPLETED, 'workflow_task_completed_event_attributes' => new WorkflowTaskCompletedEventAttributes([
            'identity' => 'worker@host',
        ])]),
        3 => new HistoryEvent(['event_id' => $id, 'event_type' => EventType::EVENT_TYPE_MARKER_RECORDED, 'marker_recorded_event_attributes' => new MarkerRecordedEventAttributes([
            'marker_name' => 'SideEffect',
            'details' => ['data' => $payloads('"a-uuid"')],
        ])]),
        default => new HistoryEvent(['event_id' => $id, 'event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED, 'workflow_execution_signaled_event_attributes' => new WorkflowExecutionSignaledEventAttributes([
            'signal_name' => 'approve',
            'input' => $payloads('{"by":"alice"}'),
        ])]),
    };
}
$wire = (new GetWorkflowExecutionHistoryResponse(['history' => new History(['events' => $events])]))->serializeToString();

/** Decodes the page from its bytes on every call, as a transport does: the baseline. */
$transport = new class ($wire) extends AbstractWorkflowServiceClient {
    public function __construct(private readonly string $wire) {}

    protected function call(string $rpc, Message $request, string $responseClass, array $metadata, array $options): Message
    {
        $response = new $responseClass();
        $response->mergeFromString($this->wire);

        return $response;
    }
};
$noop = new class implements PayloadCodecInterface {
    public function encode(Payload $payload): Payload
    {
        return $payload;
    }

    public function decode(Payload $payload): Payload
    {
        return $payload;
    }
};

$median = static function (callable $call) use ($rounds): float {
    $call(); // warm-up: autoloading and the descriptor pool
    $times = [];
    for ($round = 0; $round < $rounds; ++$round) {
        $start = hrtime(true);
        $call();
        $times[] = (float) (hrtime(true) - $start) / 1e6;
    }
    sort($times);

    return $times[intdiv($rounds, 2)];
};
$request = new GetWorkflowExecutionHistoryRequest(['namespace' => 'default']);
$bare = $median(static fn() => $transport->GetWorkflowExecutionHistory($request));
$coded = $median(static fn() => (new PayloadCodecWorkflowServiceClient($transport, $noop))->GetWorkflowExecutionHistory($request));

printf(
    "PHP %s, protobuf %s, %d events, %d bytes, median of %d\nno codec:      %8.2f ms\nno-op codec:   %8.2f ms\nwalk:          %8.2f ms (%.1f %% of the decode)\n",
    \PHP_VERSION,
    \extension_loaded('protobuf') ? 'C extension' : 'pure PHP',
    \count($events),
    \strlen($wire),
    $rounds,
    $bare,
    $coded,
    $coded - $bare,
    100.0 * ($coded - $bare) / $bare,
);
