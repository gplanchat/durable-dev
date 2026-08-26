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
use Temporal\Api\Update\V1\Request as UpdateRequest;
use Temporal\Api\Update\V1\Response as UpdateResponse;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;

/**
 * Le protocole d'update, côté worker.
 *
 * Sondé contre un vrai serveur (tâche 1.3 du change workflow-conditions-and-handler-dispatch) :
 * un update n'arrive **pas** par l'historique. Il vient à côté, en message de protocole sur la
 * tâche, et le worker l'accepte *et* y répond sur cette **même** tâche — une `Acceptance` portée
 * par une commande `PROTOCOL_MESSAGE`, une `Response` qui porte l'issue. Le serveur écrit ensuite
 * `WORKFLOW_EXECUTION_UPDATE_ACCEPTED` puis `..._UPDATE_COMPLETED`, les deux événements que
 * {@see TemporalExecutionHistory} lit déjà.
 *
 * C'est pourquoi rien ici ne ressemble à la livraison d'un signal : un signal *est* un événement,
 * un update ne le devient qu'une fois accepté.
 */
final class UpdateProtocol
{
    private function __construct() {}

    /**
     * Extrait de la tâche les updates à traiter dans cette passe.
     *
     * @return list<InboundUpdate>
     */
    public static function inboundFrom(PollWorkflowTaskQueueResponse $poll): array
    {
        $inbound = [];
        foreach ($poll->getMessages() as $message) {
            $body = $message->getBody();
            if (null === $body || !str_contains((string) $body->getTypeUrl(), 'update.v1.Request')) {
                continue;
            }

            $request = new UpdateRequest();
            $request->mergeFromString($body->getValue());

            $input = $request->getInput();
            $name = null !== $input ? (string) $input->getName() : '';
            if ('' === $name) {
                continue;
            }

            $inbound[] = new InboundUpdate(
                new PendingUpdate($name, self::decodeArguments($request)),
                (string) $message->getId(),
                (int) $message->getEventId(),
                $request,
            );
        }

        return $inbound;
    }

    /**
     * Acceptation et réponse, pour les updates que la passe a traités.
     *
     * Les deux partent sur la tâche courante : rien n'attend un second aller-retour.
     *
     * @param list<InboundUpdate> $inbound
     *
     * @return array{commands: list<Command>, messages: list<Message>}
     */
    public static function reply(array $inbound): array
    {
        $commands = [];
        $messages = [];

        foreach ($inbound as $update) {
            if (!$update->pending->handled) {
                // Aucun handler déclaré pour ce nom : ne rien accepter, l'update reste ouvert
                // plutôt que d'être clos sur une issue vide.
                continue;
            }

            $updateId = (string) $update->request->getMeta()?->getUpdateId();

            $acceptance = new Acceptance();
            $acceptance->setAcceptedRequestMessageId($update->messageId);
            $acceptance->setAcceptedRequestSequencingEventId($update->sequencingEventId);
            $acceptance->setAcceptedRequest($update->request);

            $acceptMessage = new Message();
            $acceptMessage->setId($updateId . '/accept');
            $acceptMessage->setProtocolInstanceId($updateId);
            $acceptMessage->setBody(self::pack($acceptance));

            $responseMessage = new Message();
            $responseMessage->setId($updateId . '/complete');
            $responseMessage->setProtocolInstanceId($updateId);
            $responseMessage->setBody(self::pack(self::outcomeOf($update)));

            // Une commande par message. L'acceptation seule suffit au serveur pour ouvrir
            // l'update, mais laisse la réponse hors de la séquence : si le workflow se termine
            // sur la même tâche — ce qu'un update débloquant provoque justement — le serveur
            // clôt l'exécution avant d'avoir délivré l'issue, et l'appelant reçoit « the
            // Workflow completed before the Update completed ».
            $commands[] = self::protocolCommand($acceptMessage->getId());
            $commands[] = self::protocolCommand($responseMessage->getId());
            $messages[] = $acceptMessage;
            $messages[] = $responseMessage;
        }

        return ['commands' => $commands, 'messages' => $messages];
    }

    private static function protocolCommand(string $messageId): Command
    {
        $command = new Command();
        $command->setCommandType(CommandType::COMMAND_TYPE_PROTOCOL_MESSAGE);
        $command->setProtocolMessageCommandAttributes(new ProtocolMessageCommandAttributes(['message_id' => $messageId]));

        return $command;
    }

    private static function outcomeOf(InboundUpdate $update): UpdateResponse
    {
        $outcome = new Outcome();
        $failure = $update->pending->failure;
        if (null !== $failure) {
            $outcome->setFailure(new Failure(['message' => $failure->message]));
        } else {
            $outcome->setSuccess(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($update->pending->result)));
        }

        $response = new UpdateResponse();
        $response->setMeta($update->request->getMeta());
        $response->setOutcome($outcome);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeArguments(UpdateRequest $request): array
    {
        $args = $request->getInput()?->getArgs()?->getPayloads();
        if (null === $args || 0 === $args->count()) {
            return [];
        }

        $decoded = JsonPlainPayload::decode($args[0]);

        return \is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    private static function pack(\Google\Protobuf\Internal\Message $message): Any
    {
        $any = new Any();
        $any->pack($message);

        return $any;
    }
}
