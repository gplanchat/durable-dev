<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Laravel;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Laravel\Queue\LaravelWorkflowResumeDispatcher;
use Gplanchat\Durable\Laravel\Queue\ResumeWorkflowJob;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use unit\DurableLaravel\Fixtures\FakeQueue;
use unit\DurableLaravel\Fixtures\FakeQueueFactory;

#[AsWorkflow(name: 'test.greeting')]
final class GreetingWorkflow
{
    #[AsWorkflowMethod]
    public function run(): void {}
}

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

        // A resume that arrived before the metadata would not know what to replay.
        $saved = $metadata->get('exec-2');
        self::assertNotNull($saved);
        self::assertSame('Greeting', $saved['workflowType']);
        self::assertCount(1, $queue->pushed);
    }

    /** #258: a caller passing `::class` gets the alias, the name the journal and the dashboard use. */
    public function testANewRunStartedByClassIsRecordedUnderItsAlias(): void
    {
        $metadata = new InMemoryWorkflowMetadataStore();
        $dispatcher = new LaravelWorkflowResumeDispatcher(new FakeQueueFactory(new FakeQueue()), $metadata);

        $dispatcher->dispatchNewWorkflowRun('exec-3', GreetingWorkflow::class, []);

        self::assertSame('test.greeting', $metadata->get('exec-3')['workflowType'] ?? null);
    }

    public function testAQueueThatRunsInlineIsRefusedAtBoot(): void
    {
        $app = new Container();
        // The guard reads the **configured driver**, not the connection class: `SyncQueue` lives
        // in `illuminate/queue`, which this package does not require — see the CI Laravel matrix.
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
