<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Laravel;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Laravel\Queue\LaravelActivityTransport;
use Gplanchat\Durable\Laravel\Queue\RunActivityJob;
use Gplanchat\Durable\Transport\ActivityMessage;
use PHPUnit\Framework\TestCase;
use unit\DurableLaravel\Fixtures\FakeJob;
use unit\DurableLaravel\Fixtures\FakeQueue;
use unit\DurableLaravel\Fixtures\FakeQueueFactory;

final class LaravelActivityTransportTest extends TestCase
{
    public function testAnActivityIsPushedOnTheApplicationsOwnQueue(): void
    {
        $queue = new FakeQueue();
        $transport = new LaravelActivityTransport(new FakeQueueFactory($queue), 'redis', 'durable');

        $transport->enqueue(new ActivityMessage('exec-1', 'act-1', 'Charge', ['amount' => 10]));

        self::assertCount(1, $queue->pushed);
        self::assertInstanceOf(RunActivityJob::class, $queue->pushed[0]['job']);
        self::assertSame('durable', $queue->pushed[0]['queue']);
        self::assertNull($queue->pushed[0]['delay'], 'sans report, un push simple');
    }

    public function testARetryDelayBecomesTheQueuesOwnDelayAndLeavesTheMessage(): void
    {
        $queue = new FakeQueue();
        $transport = new LaravelActivityTransport(new FakeQueueFactory($queue));

        $transport->enqueue(new ActivityMessage(
            'exec-1',
            'act-1',
            'Charge',
            [],
            retryDelay: Duration::seconds(2.4),
        ));

        // Le report devient celui de la file — arrondi au-dessus, parce qu'attendre moins que
        // demandé est la seule erreur qui compte ici.
        self::assertSame(3, $queue->pushed[0]['delay']);

        /** @var RunActivityJob $job */
        $job = $queue->pushed[0]['job'];
        // …et disparaît du message : un délai qui survit à la mise en file serait attendu deux fois.
        self::assertNull($job->message->retryDelay);
    }

    public function testDequeueReturnsTheMessageAndAcknowledgesTheJob(): void
    {
        $message = new ActivityMessage('exec-1', 'act-1', 'Charge', ['amount' => 10]);
        $job = new FakeJob(new RunActivityJob($message));
        $transport = new LaravelActivityTransport(new FakeQueueFactory(new FakeQueue([$job])));

        $popped = $transport->dequeue();

        self::assertInstanceOf(ActivityMessage::class, $popped);
        self::assertSame('act-1', $popped->activityId);
        self::assertTrue($job->deleted, 'un job rendu et jamais acquitté repasserait à chaque tour');
    }

    public function testIsEmptyKeepsWhatItPoppedToAnswer(): void
    {
        $job = new FakeJob(new RunActivityJob(new ActivityMessage('exec-1', 'act-1', 'Charge', [])));
        $transport = new LaravelActivityTransport(new FakeQueueFactory(new FakeQueue([$job])));

        self::assertFalse($transport->isEmpty());
        // Le job que la question a dépilé est toujours là : sinon `isEmpty()` répondrait juste une
        // fois et perdrait du travail à chaque appel.
        self::assertNotNull($transport->dequeue());
    }

    public function testAnEmptyQueueHasNoNextDueDate(): void
    {
        $transport = new LaravelActivityTransport(new FakeQueueFactory(new FakeQueue()));

        self::assertTrue($transport->isEmpty());
        self::assertNull($transport->nextDueAt());
    }
}
