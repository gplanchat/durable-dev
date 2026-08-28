<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Queue;

use Gplanchat\Durable\Transport\ActivityMessage;

/**
 * `ActivityMessage` ⇄ JSON, parce que la file de Magento est un tuyau d'octets et rien d'autre.
 *
 * L'encodeur de Magento existe pour déplacer des contrats de service Magento ; les charges de
 * Durable n'en sont pas, et le §4.1 l'a mesuré dans les deux formes possibles. Le module encode
 * donc lui-même, et les topics sont déclarés `request="string"`.
 *
 * Ce qu'il ne sait pas encore porter — `options` et `retryDelay`, deux objets riches — il le
 * **refuse en le nommant**. Une activité ordinaire n'en a aucun des deux : c'est mesuré, pas
 * supposé. Le jour où une activité en portera, la panne sera un refus au moment de la mise en
 * file, pas une politique de retentative silencieusement perdue.
 */
final class ActivityMessageCodec
{
    public function encode(ActivityMessage $message): string
    {
        $unrepresentable = [];
        if ($message->options !== null) {
            $unrepresentable[] = 'options';
        }
        if ($message->retryDelay !== null) {
            $unrepresentable[] = 'retryDelay';
        }

        if ($unrepresentable !== []) {
            throw UncarryableMessageException::fields($unrepresentable);
        }

        return json_encode([
            'executionId' => $message->executionId,
            'activityId' => $message->activityId,
            'activityName' => $message->activityName,
            'payload' => $message->payload,
            'attempt' => $message->attempt,
            'firstQueuedAt' => $message->firstQueuedAt,
        ], JSON_THROW_ON_ERROR);
    }

    public function decode(string $encoded): ActivityMessage
    {
        /** @var array{executionId: string, activityId: string, activityName: string, payload: array<string, mixed>, attempt: int, firstQueuedAt: float|null} $data */
        $data = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        return new ActivityMessage(
            executionId: $data['executionId'],
            activityId: $data['activityId'],
            activityName: $data['activityName'],
            payload: $data['payload'],
            attempt: $data['attempt'],
            firstQueuedAt: $data['firstQueuedAt'],
        );
    }
}
