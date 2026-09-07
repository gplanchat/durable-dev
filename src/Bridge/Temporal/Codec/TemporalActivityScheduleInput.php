<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Codec;

use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Transport\ActivityMessage;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueResponse;

/**
 * Single JSON envelope for the activity inputs scheduled by the journal interpreter
 * (executionId + identifiers + payload + metadata), decoded by {@see \Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker}.
 */
final class TemporalActivityScheduleInput
{
    /**
     * @return array{executionId: string, activityId: string, activityName: string, payload: array<string, mixed>, metadata: array<string, mixed>}
     */
    public static function encodeFromScheduled(ActivityScheduled $scheduled): array
    {
        $args = $scheduled->payload()['payload'] ?? [];
        if (!\is_array($args)) {
            $args = [];
        }

        return [
            'executionId' => $scheduled->executionId(),
            'activityId' => $scheduled->activityId(),
            'activityName' => $scheduled->activityName(),
            'payload' => $args,
            'metadata' => $scheduled->metadata(),
        ];
    }

    public static function toPayloads(ActivityScheduled $scheduled): Payloads
    {
        return JsonPlainPayload::singlePayloads(JsonPlainPayload::encode(self::encodeFromScheduled($scheduled)));
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function toActivityMessage(PollActivityTaskQueueResponse $poll): ActivityMessage
    {
        $input = $poll->getInput();
        if (null === $input) {
            throw new \InvalidArgumentException('Activity poll: missing input payloads.');
        }
        $decoded = JsonPlainPayload::decodePayloads($input);
        $first = $decoded[0] ?? null;
        if (!\is_array($first)) {
            throw new \InvalidArgumentException('Activity poll: expected JSON object in first payload.');
        }

        foreach (['executionId', 'activityId', 'activityName'] as $k) {
            if (!isset($first[$k]) || !\is_string($first[$k]) || '' === $first[$k]) {
                throw new \InvalidArgumentException('Activity poll: missing or invalid field "' . $k . '".');
            }
        }

        $payload = $first['payload'] ?? [];
        if (!\is_array($payload)) {
            $payload = [];
        }
        $metadata = $first['metadata'] ?? [];
        if (!\is_array($metadata)) {
            $metadata = [];
        }
        /* @var array<string, mixed> $payload */
        /* @var array<string, mixed> $metadata */

        // The server holds the attempt counter: it is authoritative over the one on the wire.
        $metadata['attempt'] = $poll->getAttempt();

        return ActivityMessage::fromWireMetadata(
            $first['executionId'],
            $first['activityId'],
            $first['activityName'],
            $payload,
            $metadata,
        );
    }
}
