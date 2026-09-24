<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Grpc;

use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\Grpc\GrpcTransport;
use Gplanchat\Bridge\Temporal\Grpc\RetryingGrpcTransport;
use Gplanchat\Bridge\Temporal\Http\GrpcWire;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\TransportException;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;

final class RetryingGrpcTransportTest extends TestCase
{
    private const PATH = '/temporal.api.workflowservice.v1.WorkflowService/';

    /** @var list<int> */
    private array $slept = [];

    public function testAPollThatMeetsARestartingFrontendIsRetriedUntilItAnswers(): void
    {
        $inner = $this->failing([GrpcWire::UNAVAILABLE, GrpcWire::UNAVAILABLE]);

        $response = $this->retrying($inner)->unary(self::PATH . 'PollWorkflowTaskQueue', new PollWorkflowTaskQueueRequest(), PollWorkflowTaskQueueResponse::class, [], null);

        self::assertInstanceOf(PollWorkflowTaskQueueResponse::class, $response);
        self::assertCount(3, $inner->requests);
        self::assertCount(2, $this->slept);
    }

    public function testTheRetriesAreBoundedAndTheLastFailureIsATransportException(): void
    {
        $inner = $this->failing(array_fill(0, 10, GrpcWire::DEADLINE_EXCEEDED));

        try {
            $this->retrying($inner)->unary(self::PATH . 'PollWorkflowTaskQueue', new PollWorkflowTaskQueueRequest(), PollWorkflowTaskQueueResponse::class, [], null);
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertSame(GrpcWire::DEADLINE_EXCEEDED, $e->getCode());
        }
        self::assertCount(3, $inner->requests);
        foreach ($this->slept as $i => $ms) {
            self::assertGreaterThanOrEqual(0, $ms);
            self::assertLessThanOrEqual(100 * 2 ** $i, $ms);
        }
    }

    public function testACallThatUsedTheWholeBudgetIsNotSentAgain(): void
    {
        $inner = $this->failing([GrpcWire::DEADLINE_EXCEEDED]);
        $now = 0;
        $transport = new RetryingGrpcTransport($inner, budgetMs: 30_000, sleep: static function (): void {}, clock: static function () use (&$now): int {
            $at = $now;
            $now += 60_000;

            return $at;
        });

        $this->expectExceptionCode(GrpcWire::DEADLINE_EXCEEDED);

        try {
            $transport->unary(self::PATH . 'PollWorkflowTaskQueue', new PollWorkflowTaskQueueRequest(), PollWorkflowTaskQueueResponse::class, [], 60_000);
        } finally {
            self::assertCount(1, $inner->requests);
        }
    }

    private function retrying(GrpcTransport $inner): RetryingGrpcTransport
    {
        return new RetryingGrpcTransport($inner, maxAttempts: 3, baseDelayMs: 100, maxDelayMs: 1000, sleep: function (int $ms): void {
            $this->slept[] = $ms;
        });
    }

    /** @param list<int> $codes the failures of the first calls, in order; then it answers */
    private function failing(array $codes): GrpcTransport
    {
        return new class ($codes) implements GrpcTransport {
            /** @var list<Message> */
            public array $requests = [];

            /** @param list<int> $codes */
            public function __construct(private array $codes) {}

            public function unary(string $method, Message $request, string $responseClass, array $metadata, ?int $timeoutMs): Message
            {
                $this->requests[] = clone $request;
                if ([] !== $this->codes) {
                    throw GrpcWire::failure(array_shift($this->codes), 'down');
                }

                return new $responseClass();
            }
        };
    }
}
