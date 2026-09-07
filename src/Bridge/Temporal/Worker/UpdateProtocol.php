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
 * The update protocol, worker side.
 *
 * Probed against a real server (task 1.3 of the workflow-conditions-and-handler-dispatch change):
 * an update does **not** arrive through the history. It comes alongside, as a protocol message on
 * the task, and the worker accepts it *and* answers it on that **same** task — an `Acceptance`
 * carried by a `PROTOCOL_MESSAGE` command, a `Response` carrying the outcome. The server then
 * writes `WORKFLOW_EXECUTION_UPDATE_ACCEPTED` then `..._UPDATE_COMPLETED`, the two events that
 * {@see TemporalExecutionHistory} already reads.
 *
 * That is why nothing here looks like signal delivery: a signal *is* an event, an update only
 * becomes one once accepted.
 */
final class UpdateProtocol
{
    private function __construct() {}

    /**
     * Extracts from the task the updates to handle in this pass.
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
     * Acceptance and response, for the updates this pass has handled.
     *
     * Both leave on the current task: nothing waits for a second round trip.
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
                // No handler declared for this name: accept nothing, the update stays open
                // rather than being closed on an empty outcome.
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

            // One command per message. The acceptance alone is enough for the server to open the
            // update, but leaves the response out of the sequence: if the workflow ends on the
            // same task — which is exactly what an unblocking update causes — the server closes
            // the execution before having delivered the outcome, and the caller gets "the
            // Workflow completed before the Update completed".
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
