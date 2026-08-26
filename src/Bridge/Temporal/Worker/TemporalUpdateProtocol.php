<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Google\Protobuf\Any;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Durable\Workflow\PendingUpdate;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Command\V1\ProtocolMessageCommandAttributes;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\Protocol\V1\Message;
use Temporal\Api\Update\V1\Acceptance;
use Temporal\Api\Update\V1\Outcome;
use Temporal\Api\Update\V1\Request;
use Temporal\Api\Update\V1\Response;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;

/**
 * Les deux moitiés du protocole d'update, côté worker (tâche 5.5, d'après la sonde 1.3).
 *
 * Ce que la sonde a établi et qui donne sa forme à cette classe : un update **n'arrive pas par
 * l'historique**. Il arrive à côté, dans le champ `messages` de la tâche, alors que l'historique
 * n'en porte encore aucune trace. Et l'acceptation *et* la réponse repartent sur cette **même**
 * tâche — deux messages de protocole plus les deux commandes qui les référencent. D'où l'absence
 * de second aller-retour ici : ce n'est pas une simplification, c'est ce que le serveur accepte.
 *
 * Les événements `UPDATE_ACCEPTED` et `UPDATE_COMPLETED` n'apparaissent qu'après cette réponse.
 * C'est ce qui rend le replay ordinaire : dès la passe suivante, l'update est dans le journal et
 * se lit comme n'importe quel message.
 */
final class TemporalUpdateProtocol
{
    private const TYPE_ACCEPTANCE = 'type.googleapis.com/temporal.api.update.v1.Acceptance';
    private const TYPE_RESPONSE = 'type.googleapis.com/temporal.api.update.v1.Response';
    private const TYPE_REQUEST = 'temporal.api.update.v1.Request';

    private function __construct() {}

    /**
     * Les updates que la tâche apporte hors journal, prêts à être remis à l'exécution.
     *
     * @return list<array{pending: PendingUpdate, message: Message, request: Request}>
     */
    public static function incoming(PollWorkflowTaskQueueResponse $poll): array
    {
        $incoming = [];
        foreach ($poll->getMessages() as $message) {
            $body = $message->getBody();
            if (null === $body || !str_contains($body->getTypeUrl(), self::TYPE_REQUEST)) {
                continue;
            }

            $request = new Request();
            $request->mergeFromString($body->getValue());
            $input = $request->getInput();
            if (null === $input) {
                continue;
            }

            $arguments = [];
            $args = $input->getArgs();
            if (null !== $args && $args->getPayloads()->count() > 0) {
                $decoded = JsonPlainPayload::decode($args->getPayloads()[0]);
                $arguments = \is_array($decoded) ? $decoded : ['value' => $decoded];
            }

            $incoming[] = [
                'pending' => new PendingUpdate($input->getName(), $arguments),
                'message' => $message,
                'request' => $request,
            ];
        }

        return $incoming;
    }

    /**
     * L'acceptation et la réponse d'un update traité, à joindre à la tâche.
     *
     * Un update que la passe n'a pas traité — aucun handler déclaré — ne produit rien : ni
     * acceptation ni refus. Le serveur le represente à la passe suivante, ce qui laisse au
     * workflow la possibilité d'enregistrer son handler plus tard.
     *
     * @param list<array{pending: PendingUpdate, message: Message, request: Request}> $incoming
     *
     * @return array{list<Message>, list<Command>}
     */
    public static function answer(array $incoming): array
    {
        $messages = [];
        $commands = [];

        foreach ($incoming as $entry) {
            $pending = $entry['pending'];
            if (!$pending->handled) {
                continue;
            }

            $request = $entry['request'];
            $updateId = $request->getMeta()?->getUpdateId() ?? '';

            $acceptance = new Acceptance();
            $acceptance->setAcceptedRequestMessageId($entry['message']->getId());
            $acceptance->setAcceptedRequestSequencingEventId($entry['message']->getEventId());
            $acceptance->setAcceptedRequest($request);

            $response = new Response();
            $response->setMeta($request->getMeta());
            $response->setOutcome(self::outcome($pending));

            foreach ([
                [$updateId . '/accept', $acceptance, self::TYPE_ACCEPTANCE],
                [$updateId . '/complete', $response, self::TYPE_RESPONSE],
            ] as [$id, $payload, $typeUrl]) {
                $messages[] = self::wrap($id, $updateId, $payload, $typeUrl);
                $commands[] = self::command($id);
            }
        }

        return [$messages, $commands];
    }

    private static function outcome(PendingUpdate $pending): Outcome
    {
        $outcome = new Outcome();

        if (null !== $pending->failure) {
            // Un handler qui relève fait échouer l'update, pas l'exécution : le serveur n'écrit
            // qu'un UPDATE_COMPLETED, dont l'issue porte la défaillance.
            $failure = new Failure();
            $failure->setMessage($pending->failure->message);
            $outcome->setFailure($failure);

            return $outcome;
        }

        $outcome->setSuccess(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($pending->result)));

        return $outcome;
    }

    private static function wrap(string $id, string $instanceId, \Google\Protobuf\Internal\Message $payload, string $typeUrl): Message
    {
        $any = new Any();
        $any->setTypeUrl($typeUrl);
        $any->setValue($payload->serializeToString());

        $message = new Message();
        $message->setId($id);
        $message->setProtocolInstanceId($instanceId);
        $message->setBody($any);

        return $message;
    }

    private static function command(string $messageId): Command
    {
        $attributes = new ProtocolMessageCommandAttributes();
        $attributes->setMessageId($messageId);

        $command = new Command();
        $command->setCommandType(CommandType::COMMAND_TYPE_PROTOCOL_MESSAGE);
        $command->setProtocolMessageCommandAttributes($attributes);

        return $command;
    }
}
