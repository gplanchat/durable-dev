<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Codec;

use Google\Protobuf\Any;
use Google\Protobuf\Descriptor;
use Google\Protobuf\DescriptorPool;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\AbstractWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\Grpc\TemporalGrpcTimeouts;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Nexus\Serving\NexusHandlerErrorType;
use Psr\Log\LoggerInterface;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Enums\V1\ActivityTaskFailedCause;
use Temporal\Api\Enums\V1\NexusHandlerErrorRetryBehavior;
use Temporal\Api\Enums\V1\WorkflowTaskFailedCause;
use Temporal\Api\Failure\V1\ApplicationFailureInfo;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\Nexus\V1\Failure as NexusFailure;
use Temporal\Api\Nexus\V1\HandlerError;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\PollNexusTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollNexusTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskFailedRequest;

/**
 * Applies a {@see PayloadCodecInterface} where every RPC passes (DUR055): the payloads of a request
 * are encoded on the way out, those of a response decoded on the way in.
 *
 * The walk reads the generated descriptors, so a message a later API version adds is covered with
 * no list to keep. Search attributes are skipped by message type: the server must index them.
 * An `Any` is unpacked, walked and packed again: the update protocol carries its requests and
 * results that way. This is where Temporal's Go SDK puts its gRPC codec interceptor, and what it
 * skips.
 *
 * The request is copied, which costs its size once more; the response is decoded in place, so the
 * inner client must hand out a response of its own, not a shared one.
 */
final class PayloadCodecWorkflowServiceClient extends AbstractWorkflowServiceClient
{
    private const SEARCH_ATTRIBUTES = 'temporal.api.common.v1.SearchAttributes';
    private const GRPC_NOT_FOUND = 5;
    private const PAYLOAD = 'temporal.api.common.v1.Payload';
    private const ANY = 'google.protobuf.Any';

    /** @var array<class-string<Message>, list<array{string, string}>> */
    private static array $payloadFields = [];

    /** The history event the decode walk is in, kept when a payload of it fails (#936). */
    private ?int $eventId = null;

    public function __construct(
        private readonly WorkflowServiceClientInterface $inner,
        private readonly PayloadCodecInterface $codec,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @template T of Message
     *
     * @param class-string<T>      $responseClass
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $options
     *
     * @return T
     */
    protected function call(string $rpc, Message $request, string $responseClass, array $metadata, array $options): Message
    {
        // A copy: a caller that sends the same request again must not find it encoded twice.
        $copy = new ($request::class)();
        $copy->mergeFromString($request->serializeToString());
        $this->walk($copy, $this->codec->encode(...));

        $response = $this->inner->{$rpc}($copy, $metadata, $options);
        \assert($response instanceof $responseClass);

        $this->eventId = null;

        try {
            $this->walk($response, $this->codec->decode(...));
        } catch (\Throwable $e) {
            if (!$this->failTask($copy, $response, $e)) {
                throw new PayloadDecodeFailure(sprintf('Payload decode failed: %s', $e->getMessage()), 0, $e, $this->eventId);
            }

            // An empty poll, as after a long poll that found nothing: every worker loop polls again.
            return new $responseClass();
        }

        return $response;
    }

    /**
     * A polled task that cannot be decoded is answered as failed (#775), here because this is the
     * one place that holds both the task token and the error. Left to throw, it stopped the worker,
     * and the next worker to receive the task stopped too.
     *
     * The failure carries the error's class and message, never its stack trace: the trace quotes
     * arguments, and those of a decrypt call are the key or the plaintext this server must not see.
     * The worker's own log gets the error itself, trace included, and the event id (#936).
     *
     * A Nexus task gets a retryable INTERNAL handler error (#824): the server delivers it again, and
     * a worker redeployed with the right codec or key serves it.
     *
     * @return bool false when the call is not a task poll, whose caller gets the error instead
     */
    private function failTask(Message $request, Message $response, \Throwable $error): bool
    {
        if ($request instanceof PollWorkflowTaskQueueRequest) {
            $failed = new RespondWorkflowTaskFailedRequest(['cause' => WorkflowTaskFailedCause::WORKFLOW_TASK_FAILED_CAUSE_WORKFLOW_WORKER_UNHANDLED_FAILURE]);
            $rpc = 'RespondWorkflowTaskFailed';
        } elseif ($request instanceof PollActivityTaskQueueRequest) {
            // A server older than this field ignores it, as proto3 does with any unknown field.
            $failed = new RespondActivityTaskFailedRequest(['cause' => ActivityTaskFailedCause::ACTIVITY_TASK_FAILED_CAUSE_ACTIVITY_WORKER_UNHANDLED_FAILURE]);
            $rpc = 'RespondActivityTaskFailed';
        } elseif ($request instanceof PollNexusTaskQueueRequest) {
            $failed = new RespondNexusTaskFailedRequest(['error' => new HandlerError([
                'error_type' => NexusHandlerErrorType::Internal->value,
                'retry_behavior' => NexusHandlerErrorRetryBehavior::NEXUS_HANDLER_ERROR_RETRY_BEHAVIOR_RETRYABLE,
                'failure' => new NexusFailure(['message' => sprintf('Payload decode failed: %s', $error->getMessage())]),
            ])]);
            $rpc = 'RespondNexusTaskFailed';
        } else {
            return false;
        }
        \assert(method_exists($response, 'getTaskToken'));
        $this->logger?->error('A task payload cannot be decoded; the worker answers the task as failed.', [
            'exception' => $error,
            'event_id' => $this->eventId,
            'rpc' => $rpc,
            // What identifies the task, from the poll response: none of it is sensitive (#939).
            ...match (true) {
                $response instanceof PollWorkflowTaskQueueResponse => [
                    'workflow_id' => $response->getWorkflowExecution()?->getWorkflowId(),
                    'run_id' => $response->getWorkflowExecution()?->getRunId(),
                ],
                $response instanceof PollActivityTaskQueueResponse => [
                    'workflow_id' => $response->getWorkflowExecution()?->getWorkflowId(),
                    'run_id' => $response->getWorkflowExecution()?->getRunId(),
                    'activity_id' => $response->getActivityId(),
                ],
                $response instanceof PollNexusTaskQueueResponse => self::nexusTaskContext($response),
                default => [],
            },
        ]);

        if (!$failed instanceof RespondNexusTaskFailedRequest) {
            $failure = new Failure();
            $failure->setMessage(sprintf('Payload decode failed: %s', $error->getMessage()));
            $failure->setSource('DurablePayloadCodec');
            $failure->setApplicationFailureInfo(new ApplicationFailureInfo(['type' => $error::class]));
            $failed->setFailure($failure);
        }
        $failed->setNamespace($request->getNamespace());
        $failed->setIdentity($request->getIdentity());
        $failed->setTaskToken($response->getTaskToken());

        try {
            $this->inner->{$rpc}($failed, [], ['timeout' => TemporalGrpcTimeouts::SHORT_US]);
        } catch (\RuntimeException $e) {
            // The task already closed or timed out: nothing is left to answer.
            if (self::GRPC_NOT_FOUND !== $e->getCode()) {
                throw $e;
            }
        }

        return true;
    }

    /**
     * A Nexus task has no workflow id: the poll response names its service and operation, and a
     * start request its request id.
     *
     * @return array<string, string>
     */
    private static function nexusTaskContext(PollNexusTaskQueueResponse $response): array
    {
        $start = $response->getRequest()?->getStartOperation();
        $call = $start ?? $response->getRequest()?->getCancelOperation();
        if (null === $call) {
            return [];
        }

        return [
            'service' => $call->getService(),
            'operation' => $call->getOperation(),
            ...(null === $start ? [] : ['request_id' => $start->getRequestId()]),
        ];
    }

    /**
     * @param \Closure(Payload): Payload $transform
     */
    private function walk(Message $message, \Closure $transform): void
    {
        if ($message instanceof Any) {
            // An unknown type throws here rather than leaving its payloads untouched.
            $packed = $message->unpack();
            $this->walk($packed, $transform);
            $message->pack($packed);

            return;
        }
        if ($message instanceof HistoryEvent) {
            $this->eventId = (int) $message->getEventId();
        }
        foreach (self::payloadFieldsOf($message::class) as [$getter, $setter]) {
            $value = $message->{$getter}();
            if ($value instanceof Payload) {
                $message->{$setter}($transform($value));
            } elseif ($value instanceof Message) {
                $this->walk($value, $transform);
            } elseif (is_iterable($value)) {
                // A repeated field or a map: both are replaced item by item, keys kept.
                foreach ($value as $key => $item) {
                    if ($item instanceof Payload) {
                        $value[$key] = $transform($item);
                    } elseif ($item instanceof Message) {
                        $this->walk($item, $transform);
                    }
                }
            }
        }
        if ($message instanceof HistoryEvent) {
            // Read whole: a later failure, outside the history, is not this event's.
            $this->eventId = null;
        }
    }

    /**
     * The fields of a message class that can lead to a payload, as getter and setter names: read
     * from the descriptors once per class, then walked without them (#764). A field whose type holds
     * no payload at any depth is left out; so are search attributes.
     *
     * @param class-string<Message> $class
     *
     * @return list<array{string, string}>
     */
    private static function payloadFieldsOf(string $class): array
    {
        if (isset(self::$payloadFields[$class])) {
            return self::$payloadFields[$class];
        }
        $descriptor = DescriptorPool::getGeneratedPool()->getDescriptorByClassName($class);
        $fields = [];
        if (self::SEARCH_ATTRIBUTES !== $descriptor->getFullName()) {
            for ($index = 0; $index < $descriptor->getFieldCount(); ++$index) {
                $field = $descriptor->getField($index);
                if (GPBType::MESSAGE === $field->getType() && self::leadsToAPayload($field->getMessageType(), [])) {
                    $name = str_replace('_', '', ucwords($field->getName(), '_'));
                    $fields[] = ['get' . $name, 'set' . $name];
                }
            }
        }

        return self::$payloadFields[$class] = $fields;
    }

    /**
     * An `Any` may pack anything, so it counts as a payload. A map field's type is its entry, whose
     * value field this follows like any other. A type met again on the way down is a cycle
     * (`Failure.cause`): it adds nothing the first visit does not already check.
     *
     * @param array<string, true> $visiting
     */
    private static function leadsToAPayload(Descriptor $descriptor, array $visiting): bool
    {
        $name = $descriptor->getFullName();
        if (self::PAYLOAD === $name || self::ANY === $name) {
            return true;
        }
        if (self::SEARCH_ATTRIBUTES === $name || isset($visiting[$name])) {
            return false;
        }
        $visiting[$name] = true;
        for ($index = 0; $index < $descriptor->getFieldCount(); ++$index) {
            $field = $descriptor->getField($index);
            if (GPBType::MESSAGE === $field->getType() && self::leadsToAPayload($field->getMessageType(), $visiting)) {
                return true;
            }
        }

        return false;
    }
}
