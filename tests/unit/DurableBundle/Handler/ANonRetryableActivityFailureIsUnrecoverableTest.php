<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Bundle\Handler;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Bundle\Handler\ActivityRunHandler;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class PaymentRefused extends \RuntimeException {}

/**
 * The core decides that a failure is not worth retrying; Messenger must hear it (#341). Without
 * that, the handler returned normally, and `failure_transport` never saw the one failure an
 * operator most needs to find there.
 */
final class ANonRetryableActivityFailureIsUnrecoverableTest extends TestCase
{
    public function testMessengerNeitherRetriesItNorTakesItForAnAcknowledgedSuccess(): void
    {
        $executor = new RegistryActivityExecutor();
        $executor->register('charge', static fn(): never => throw new PaymentRefused('card declined'));
        $handler = new ActivityRunHandler(new ActivityMessageProcessor(
            new InMemoryEventStore(),
            new InMemoryActivityTransport(),
            $executor,
            new NullWorkflowResumeDispatcher(),
            $this->createMock(ActivityHeartbeatSenderInterface::class),
            5,
        ));

        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new ActivityMessage('exec-1', 'act-1', 'charge', [], ActivityOptions::of(5, nonRetryableExceptions: [PaymentRefused::class]))));

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));
        // Messenger's own retry, as configured by `retry_strategy`: it must not run.
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            new class ([ 'activities' => $transport ]) implements \Psr\Container\ContainerInterface {
                /** @param array<string, object> $senders */
                public function __construct(private readonly array $senders) {}

                public function get(string $id): mixed
                {
                    return $this->senders[$id];
                }

                public function has(string $id): bool
                {
                    return isset($this->senders[$id]);
                }
            },
            new class (['activities' => new MultiplierRetryStrategy(3)]) implements \Psr\Container\ContainerInterface {
                /** @param array<string, object> $strategies */
                public function __construct(private readonly array $strategies) {}

                public function get(string $id): mixed
                {
                    return $this->strategies[$id];
                }

                public function has(string $id): bool
                {
                    return isset($this->strategies[$id]);
                }
            },
        ));
        $failure = null;
        $dispatcher->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use (&$failure): void {
            $failure = $event;
        }, -1000);

        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([ActivityMessage::class => [$handler]]))]);
        (new Worker(['activities' => $transport], $bus, $dispatcher))->run();

        self::assertInstanceOf(WorkerMessageFailedEvent::class, $failure, 'the failure reaches Messenger');
        self::assertFalse($failure->willRetry());
        $thrown = $failure->getThrowable();
        self::assertInstanceOf(HandlerFailedException::class, $thrown);
        self::assertNotSame([], $thrown->getWrappedExceptions(UnrecoverableMessageHandlingException::class, true));
        self::assertCount(1, $transport->getSent(), 'nothing sent back for a retry');
    }
}
