<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Codec;

use Google\Protobuf\Any;
use Gplanchat\Bridge\Temporal\Codec\PayloadCodecInterface;
use Gplanchat\Bridge\Temporal\Codec\PayloadCodecWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Header;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\SearchAttributes;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionStartedEventAttributes;
use Temporal\Api\Protocol\V1\Message as ProtocolMessage;
use Temporal\Api\Update\V1\Input as UpdateInput;
use Temporal\Api\Update\V1\Outcome;
use Temporal\Api\Update\V1\Request as UpdateRequest;
use Temporal\Api\Update\V1\Response as UpdateResponse;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryRequest;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedResponse;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionResponse;

/**
 * DUR055: every payload the application sends is encoded, and every payload it reads is decoded,
 * at the one place all RPCs pass — search attributes excepted, which the server must index.
 */
final class PayloadCodecWorkflowServiceClientTest extends TestCase
{
    public function testARequestLeavesWithItsPayloadsEncodedAndItsSearchAttributesInClear(): void
    {
        $sent = null;
        $inner = $this->createMock(WorkflowServiceClientInterface::class);
        $inner->method('StartWorkflowExecution')->willReturnCallback(static function (StartWorkflowExecutionRequest $request) use (&$sent): StartWorkflowExecutionResponse {
            $sent = $request;

            return new StartWorkflowExecutionResponse();
        });

        $request = new StartWorkflowExecutionRequest();
        $request->setInput(new Payloads(['payloads' => [new Payload(['data' => 'order-42'])]]));
        $request->setHeader(new Header(['fields' => ['trace' => new Payload(['data' => 'abc'])]]));
        $request->setSearchAttributes(new SearchAttributes(['indexed_fields' => ['DurableExecutionId' => new Payload(['data' => '"order-42"'])]]));

        (new PayloadCodecWorkflowServiceClient($inner, new ReversingCodec()))->StartWorkflowExecution($request);

        self::assertInstanceOf(StartWorkflowExecutionRequest::class, $sent);
        self::assertSame('24-redro', $sent->getInput()?->getPayloads()[0]->getData());
        self::assertSame('cba', $sent->getHeader()?->getFields()['trace']->getData());
        self::assertSame('"order-42"', $sent->getSearchAttributes()?->getIndexedFields()['DurableExecutionId']->getData());
    }

    /**
     * A caller that sends the same request twice (a retry) must not find it encoded twice.
     */
    public function testTheCallersRequestIsLeftAsItWas(): void
    {
        $inner = $this->createMock(WorkflowServiceClientInterface::class);
        $inner->method('StartWorkflowExecution')->willReturn(new StartWorkflowExecutionResponse());
        $request = new StartWorkflowExecutionRequest();
        $request->setInput(new Payloads(['payloads' => [new Payload(['data' => 'order-42'])]]));

        (new PayloadCodecWorkflowServiceClient($inner, new ReversingCodec()))->StartWorkflowExecution($request);

        self::assertSame('order-42', $request->getInput()?->getPayloads()[0]->getData());
    }

    public function testAResponseArrivesWithItsPayloadsDecoded(): void
    {
        $started = new WorkflowExecutionStartedEventAttributes();
        $started->setInput(new Payloads(['payloads' => [ReversingCodec::encoded('order-42')]]));
        $response = new GetWorkflowExecutionHistoryResponse();
        $response->setHistory(new History(['events' => [new HistoryEvent(['workflow_execution_started_event_attributes' => $started])]]));
        $inner = $this->createMock(WorkflowServiceClientInterface::class);
        $inner->method('GetWorkflowExecutionHistory')->willReturn($response);

        $read = (new PayloadCodecWorkflowServiceClient($inner, new ReversingCodec()))->GetWorkflowExecutionHistory(new GetWorkflowExecutionHistoryRequest());

        $input = $read->getHistory()?->getEvents()[0]->getWorkflowExecutionStartedEventAttributes()?->getInput();
        self::assertSame('order-42', $input?->getPayloads()[0]->getData());
    }

    /**
     * The update protocol packs its messages in `google.protobuf.Any`: a walk that stops at the
     * Any lets update arguments in encoded and update results out in clear.
     */
    public function testAnUpdatePackedInAnAnyIsDecodedOnTheWayIn(): void
    {
        $request = new UpdateRequest(['input' => new UpdateInput(['name' => 'rename', 'args' => new Payloads(['payloads' => [ReversingCodec::encoded('order-42')]])])]);
        $body = new Any();
        $body->pack($request);
        $inner = $this->createMock(WorkflowServiceClientInterface::class);
        $inner->method('PollWorkflowTaskQueue')->willReturn(new PollWorkflowTaskQueueResponse(['messages' => [new ProtocolMessage(['body' => $body])]]));

        $poll = (new PayloadCodecWorkflowServiceClient($inner, new ReversingCodec()))->PollWorkflowTaskQueue(new PollWorkflowTaskQueueRequest());

        $unpacked = $poll->getMessages()[0]->getBody()?->unpack();
        self::assertInstanceOf(UpdateRequest::class, $unpacked);
        self::assertSame('order-42', $unpacked->getInput()?->getArgs()?->getPayloads()[0]->getData());
    }

    public function testAnUpdateResultPackedInAnAnyLeavesEncoded(): void
    {
        $sent = null;
        $inner = $this->createMock(WorkflowServiceClientInterface::class);
        $inner->method('RespondWorkflowTaskCompleted')->willReturnCallback(static function (RespondWorkflowTaskCompletedRequest $request) use (&$sent): RespondWorkflowTaskCompletedResponse {
            $sent = $request;

            return new RespondWorkflowTaskCompletedResponse();
        });
        $body = new Any();
        $body->pack(new UpdateResponse(['outcome' => new Outcome(['success' => new Payloads(['payloads' => [new Payload(['data' => 'order-42'])]])])]));

        (new PayloadCodecWorkflowServiceClient($inner, new ReversingCodec()))->RespondWorkflowTaskCompleted(new RespondWorkflowTaskCompletedRequest(['messages' => [new ProtocolMessage(['body' => $body])]]));

        self::assertInstanceOf(RespondWorkflowTaskCompletedRequest::class, $sent);
        $response = $sent->getMessages()[0]->getBody()?->unpack();
        self::assertInstanceOf(UpdateResponse::class, $response);
        self::assertSame('24-redro', $response->getOutcome()?->getSuccess()?->getPayloads()[0]->getData());
    }

    public function testSearchAttributesInAResponseAreNotDecoded(): void
    {
        $started = new WorkflowExecutionStartedEventAttributes();
        $started->setSearchAttributes(new SearchAttributes(['indexed_fields' => ['K' => ReversingCodec::encoded('left alone')]]));
        $response = new GetWorkflowExecutionHistoryResponse();
        $response->setHistory(new History(['events' => [new HistoryEvent(['workflow_execution_started_event_attributes' => $started])]]));
        $inner = $this->createMock(WorkflowServiceClientInterface::class);
        $inner->method('GetWorkflowExecutionHistory')->willReturn($response);

        $read = (new PayloadCodecWorkflowServiceClient($inner, new ReversingCodec()))->GetWorkflowExecutionHistory(new GetWorkflowExecutionHistoryRequest());

        $attributes = $read->getHistory()?->getEvents()[0]->getWorkflowExecutionStartedEventAttributes()?->getSearchAttributes();
        self::assertSame(strrev('left alone'), $attributes?->getIndexedFields()['K']->getData());
    }

}

/**
 * Reverses the bytes and marks the payload: reversible, visible, and not a cipher.
 */
final class ReversingCodec implements PayloadCodecInterface
{
    public static function encoded(string $data): Payload
    {
        return new Payload(['metadata' => ['encoding' => 'test/reversed'], 'data' => strrev($data)]);
    }

    public function encode(Payload $payload): Payload
    {
        return self::encoded($payload->getData());
    }

    public function decode(Payload $payload): Payload
    {
        if ('test/reversed' !== (iterator_to_array($payload->getMetadata())['encoding'] ?? null)) {
            return $payload;
        }

        return new Payload(['metadata' => ['encoding' => 'json/plain'], 'data' => strrev($payload->getData())]);
    }
}
