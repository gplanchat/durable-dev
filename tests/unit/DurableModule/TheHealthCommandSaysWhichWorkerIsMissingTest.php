<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Http\GrpcWire;
use Gplanchat\DurableModule\Console\Command\HealthCommand;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Temporal\Api\Taskqueue\V1\PollerInfo;
use Temporal\Api\Workflowservice\V1\DescribeTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\DescribeTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsResponse;

/**
 * Without the activity worker an execution stops at its first activity, and nothing fails: the
 * operator hears it from the customer. This command is what a supervisor can alert on instead.
 */
final class TheHealthCommandSaysWhichWorkerIsMissingTest extends TestCase
{
    public function testBothWorkersPollingIsHealthy(): void
    {
        $tester = $this->probe(['durable-workflows' => time(), 'durable-activities' => time()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('poller(s) on durable-workflows', $tester->getDisplay());
        self::assertStringContainsString('poller(s) on durable-activities', $tester->getDisplay());
    }

    public function testAnActivityWorkerThatStoppedFailsAndIsNamed(): void
    {
        // Still listed by the server, but its last poll is five minutes old.
        $tester = $this->probe(['durable-workflows' => time(), 'durable-activities' => time() - 300]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--role=activity', $tester->getDisplay(), 'say what to start');
        self::assertStringNotContainsString('--role=journal', $tester->getDisplay());
    }

    public function testAClusterThatDoesNotAnswerFails(): void
    {
        $tester = $this->probe([], answering: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringNotContainsString('--role=', $tester->getDisplay(), 'no worker to start when the cluster is the problem');
    }

    public function testAQueueTheClusterWouldNotDescribeFailsWithoutBlamingTheWorker(): void
    {
        $tester = $this->probe(['durable-workflows' => time()], refusing: 'durable-activities');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('could not ask', $tester->getDisplay());
        self::assertStringNotContainsString('--role=activity', $tester->getDisplay());
    }

    public function testWithoutAClusterThereIsNoWorkerToMiss(): void
    {
        $tester = new CommandTester(new HealthCommand(new RuntimeFactory()));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('durable/temporal/dsn', $tester->getDisplay());
    }

    /**
     * @param array<string, int> $lastPolls queue name => unix time of its one poller's last poll
     */
    private function probe(array $lastPolls, bool $answering = true, ?string $refusing = null): CommandTester
    {
        $cluster = static function (RequestInterface $request, array $options) use ($lastPolls, $answering, $refusing): PromiseInterface {
            if (!$answering) {
                // PERMISSION_DENIED: refused at once, where UNAVAILABLE would be retried for seconds.
                return self::reply($options, '', '7');
            }
            if (!str_ends_with($request->getUri()->getPath(), '/DescribeTaskQueue')) {
                return self::reply($options, (new ListWorkflowExecutionsResponse())->serializeToString(), '0');
            }
            $asked = new DescribeTaskQueueRequest();
            $asked->mergeFromString(GrpcWire::unframe((string) $request->getBody()));
            if ($asked->getTaskQueue()?->getName() === $refusing) {
                return self::reply($options, '', '7');
            }
            $response = new DescribeTaskQueueResponse();
            $at = $lastPolls[$asked->getTaskQueue()?->getName() ?? ''] ?? null;
            if (null !== $at) {
                $response->setPollers([new PollerInfo(['last_access_time' => new Timestamp(['seconds' => $at])])]);
            }

            return self::reply($options, $response->serializeToString(), '0');
        };

        $tester = new CommandTester(new HealthCommand(new RuntimeFactory(
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0&transport=guzzle',
            guzzle: new Client(['handler' => $cluster]),
        )));
        $tester->execute([]);

        return $tester;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function reply(array $options, string $message, string $status): PromiseInterface
    {
        $response = new Response(200, ['content-type' => 'application/grpc'], GrpcWire::frame($message));
        ($options['on_trailers'])(['grpc-status' => [$status]], $response);

        return Create::promiseFor($response);
    }
}
