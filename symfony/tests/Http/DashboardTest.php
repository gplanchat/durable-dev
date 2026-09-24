<?php

declare(strict_types=1);

namespace App\Tests\Http;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
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
        $this->assertStringContainsString('ANIMATION', $normalized);
    }

    public function testDashboardFiltersByStatus(): void
    {
        $client = $this->clientWithRecordedRun('dash-run-4');
        $allCrawler = $client->request('GET', '/dashboard');
        $allCount = $allCrawler->filterXpath("//*[contains(concat(' ', normalize-space(@class), ' '), ' ds-run-row ')]")->count();

        $filteredCrawler = $client->request('GET', '/dashboard?status=failed');
        $this->assertResponseIsSuccessful();
        $filteredCount = $filteredCrawler->filterXpath("//*[contains(concat(' ', normalize-space(@class), ' '), ' ds-run-row ')]")->count();
        self::assertLessThanOrEqual($allCount, $filteredCount);

        if ($filteredCount > 0) {
            $filteredCrawler->filterXpath("//*[contains(concat(' ', normalize-space(@class), ' '), ' ds-status-badge ')]")->each(static function ($badge): void {
                self::assertSame('FAILED', \trim($badge->text()));
            });
        }
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
        $container = static::getContainer();

        $container->get(WorkflowMetadataStore::class)->save($executionId, 'GreetingWorkflow', []);
        $journal = $container->get(EventStoreInterface::class);
        $journal->append(new ExecutionStarted($executionId, []));
        $journal->append(new ActivityScheduled($executionId, 'act-1', 'SendGreeting', []));
        $journal->append(new ActivityCompleted($executionId, 'act-1', 'Hello'));
        $journal->append(new ExecutionCompleted($executionId, 'Hello'));

        return $client;
    }
}
