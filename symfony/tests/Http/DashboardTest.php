<?php

declare(strict_types=1);

namespace App\Tests\Http;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The bench dashboard, drawn from what the backend recorded (`Gplanchat\Durable\Observation`), the
 * way the Sylius plugin and Magento draw theirs.
 *
 * Each test records a run in the journal first: the page used to render made-up runs when no
 * cluster answered, so an empty backend looked busy.
 *
 * @internal
 */
final class DashboardTest extends WebTestCase
{
    public function testARecordedRunIsListedAndDrawn(): void
    {
        $client = $this->clientWithRecordedRun('dash-run-1');

        $client->request('GET', '/dashboard?run=dash-run-1');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('dash-run-1', $content);
        $this->assertStringContainsString('GreetingWorkflow', $content);
        $this->assertStringContainsString('SendGreeting', $content);
        $this->assertStringNotContainsString('demo-run-001', $content, 'no made-up run');
    }

    public function testDashboardReturns200WithWorkflowHeading(): void
    {
        $client = $this->clientWithRecordedRun('dash-run-2');
        $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Workflow Dashboard', $content);
        $this->assertStringContainsString('Running', $content);
        $this->assertStringContainsString('Execution navigation', $content);
        $this->assertStringContainsString('Showing', $content);
        $this->assertStringContainsString('Timeline frieze', $content);
        $this->assertStringContainsString('Event history', $content);
    }

    public function testDashboardShowsTimelineControlsAndLegend(): void
    {
        $client = $this->clientWithRecordedRun('dash-run-3');
        $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $normalized = strtoupper($content);
        $this->assertStringContainsString('LANES', $normalized);
        $this->assertStringContainsString('EXECUTION', $normalized);
        $this->assertStringContainsString('ACTIVITY', $normalized);
        $this->assertStringContainsString('SIGNAL', $normalized);
        $this->assertStringContainsString('QUERY', $normalized);
        $this->assertStringContainsString('UPDATE', $normalized);
        // The page carries "nexus" in its stylesheet and a static legend whatever the controller
        // does: the lane toggles are what it actually offers.
        $lanes = $client->getCrawler()->filterXPath('//input[@name="kinds[]"]')->each(static fn($input): string => (string) $input->attr('value'));
        self::assertContains('nexus', $lanes, 'a Nexus operation is the wait served elsewhere: every dashboard draws its lane');
        $this->assertStringContainsString('ANIMATION', $normalized);
    }

    /**
     * The page filters the way the port does: a cancelled run is not a failure, and each filter
     * lists its own runs only.
     */
    public function testDashboardFiltersByStatus(): void
    {
        $client = $this->clientWithRecordedRun('dash-done');
        $this->record('dash-failed', WorkflowExecutionFailed::workflowHandlerFailure('dash-failed', new \RuntimeException('boom')));
        $this->record('dash-cancelled', new WorkflowExecutionCancelled('dash-cancelled', 'stopped'));

        self::assertSame(['dash-failed' => 'FAILED'], $this->listedRuns($client, 'failed'));
        self::assertSame(['dash-cancelled' => 'CANCELLED'], $this->listedRuns($client, 'cancelled'));
        self::assertSame(['dash-done' => 'COMPLETED'], $this->listedRuns($client, 'completed'));
    }

    /**
     * @return array<string, string> run id => status badge, for the rows the filtered page lists
     */
    private function listedRuns(KernelBrowser $client, string $status): array
    {
        $crawler = $client->request('GET', '/dashboard?status=' . $status);
        $this->assertResponseIsSuccessful();

        $runs = [];
        $class = static fn(string $name): string => \sprintf("contains(concat(' ', normalize-space(@class), ' '), ' %s ')", $name);
        $crawler->filterXPath('//*[' . $class('ds-run-row') . ']')->each(static function ($row) use (&$runs, $class): void {
            $id = \trim($row->filterXPath('.//*[' . $class('ds-run-row__meta') . ']//code')->text());
            $runs[$id] = \trim($row->filterXPath('.//*[' . $class('ds-status-badge') . ']')->text());
        });

        return $runs;
    }

    /**
     * A completed run with one activity, written the way the engine writes it: the name in the
     * metadata store, the facts in the journal. One kernel for the whole test, since the journal
     * of this bench's test environment lives in memory.
     */
    private function clientWithRecordedRun(string $executionId): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->record($executionId, new ExecutionCompleted($executionId, 'Hello'));

        return $client;
    }

    /**
     * One run with one activity, ended by the given event, into the kernel the client already booted.
     */
    private function record(string $executionId, Event $ending): void
    {
        $container = static::getContainer();
        $container->get(WorkflowMetadataStore::class)->save($executionId, 'GreetingWorkflow', []);
        $journal = $container->get(EventStoreInterface::class);
        $journal->append(new ExecutionStarted($executionId, []));
        $journal->append(new ActivityScheduled($executionId, 'act-1', 'SendGreeting', []));
        $journal->append(new ActivityCompleted($executionId, 'act-1', 'Hello'));
        $journal->append($ending);
    }
}
