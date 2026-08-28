<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Laravel;

use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Laravel\Queue\LaravelWorkflowResumeDispatcher;
use Gplanchat\Durable\Laravel\Queue\ResumeWorkflowJob;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use unit\DurableLaravel\Fixtures\FakeQueue;
use unit\DurableLaravel\Fixtures\FakeQueueFactory;

final class LaravelWorkflowResumeDispatcherTest extends TestCase
{
    public function testAResumeBecomesAJobOnTheApplicationsQueue(): void
    {
        $queue = new FakeQueue();
        $dispatcher = new LaravelWorkflowResumeDispatcher(
            new FakeQueueFactory($queue),
            new InMemoryWorkflowMetadataStore(),
            null,
            'durable',
        );

        $dispatcher->dispatchResume('exec-1', [['name' => 'approve', 'arguments' => []]]);

        self::assertCount(1, $queue->pushed);
        /** @var ResumeWorkflowJob $job */
        $job = $queue->pushed[0]['job'];
        self::assertSame('exec-1', $job->message->executionId);
        self::assertSame('approve', $job->message->pendingUpdates[0]['name']);
        self::assertSame('durable', $queue->pushed[0]['queue']);
    }

    public function testANewRunSavesItsMetadataBeforeItIsQueued(): void
    {
        $queue = new FakeQueue();
        $metadata = new InMemoryWorkflowMetadataStore();
        $dispatcher = new LaravelWorkflowResumeDispatcher(new FakeQueueFactory($queue), $metadata);

        $dispatcher->dispatchNewWorkflowRun('exec-2', 'Greeting', ['who' => 'world']);

        // Une reprise qui arriverait avant les métadonnées ne saurait pas quoi rejouer.
        $saved = $metadata->get('exec-2');
        self::assertNotNull($saved);
        self::assertSame('Greeting', $saved['workflowType']);
        self::assertCount(1, $queue->pushed);
    }

    public function testAQueueThatRunsInlineIsRefusedAtBoot(): void
    {
        $app = new Container();
        // La garde lit le **driver configuré**, pas la classe de la connexion : `SyncQueue` vit
        // dans `illuminate/queue`, que ce paquet n'exige pas — voir la matrice Laravel de la CI.
        $app->instance('config', new \ArrayObject(
            [
                'durable' => ['backend' => 'illuminate'],
                'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
            ],
            \ArrayObject::ARRAY_AS_PROPS,
        ));

        $provider = new DurableServiceProvider($app);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('runs jobs inline');
        $this->expectExceptionMessage('recurses in the same process');

        $provider->boot();
    }
}
