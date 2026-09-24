<?php

declare(strict_types=1);

namespace App\Tests\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP smoke tests: verifies that the container builds correctly (routing, DI, templates)
 * and that the main pages return HTTP 200 with the expected content.
 *
 * These tests catch a wrong type injected into a Durable service, which only shows as an
 * exception during container initialization.
 *
 * @internal
 */
final class SamplesControllerSmokeTest extends WebTestCase
{
    public function testSamplesIndexReturns200(): void
    {
        $client = static::createClient();
        $client->request('GET', '/durable/samples');

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(200);
    }

    public function testSamplesIndexContainsBothButtons(): void
    {
        $client = static::createClient();
        $client->request('GET', '/durable/samples');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Synchronous', $content);
        $this->assertStringContainsString('Async', $content);
    }

    public function testSamplesIndexListsAllRegisteredScenarios(): void
    {
        $client = static::createClient();
        $client->request('GET', '/durable/samples');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        // A few representative scenarios from the catalogue
        $this->assertStringContainsString('SimpleActivity', $content);
        $this->assertStringContainsString('Signal', $content);
        $this->assertStringContainsString('BookingSaga', $content);
    }

    public function testHomeReturns200WithSamplesList(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Read the documentation', $content);
        $this->assertStringContainsString('SimpleActivity', $content);
    }

    public function testDocumentationPageReturns200(): void
    {
        $client = static::createClient();
        $client->request('GET', '/documentation');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Durable Documentation', $content);
    }

    public function testSamplesRunSyncReturns200ForSimpleActivity(): void
    {
        $client = static::createClient();
        $client->request('POST', '/durable/samples/run/simple_activity');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Hello, World!', $content);
        $this->assertStringContainsString('Synchronous', $content);
    }

    public function testSamplesRunAsyncReturns200ForSimpleActivity(): void
    {
        $client = static::createClient();
        $client->request('POST', '/durable/samples/run/simple_activity?wait=0');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Async', $content);
    }

    public function testSamplesRunUnknownIdReturns404(): void
    {
        $client = static::createClient();
        $client->request('POST', '/durable/samples/run/nonexistent-scenario');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testALinkCannotStartARun(): void
    {
        // A GET is what a prefetch, a crawler or an <img> sends: none of them may start a workflow.
        $client = static::createClient();
        $client->request('GET', '/durable/samples/run/simple_activity');

        $this->assertResponseStatusCodeSame(405);
    }

    public function testAnotherSiteCannotStartARun(): void
    {
        // A form on another site can POST here; the browser says so in Sec-Fetch-Site and Origin.
        $client = static::createClient();
        $client->request('POST', '/durable/samples/run/simple_activity', server: [
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
            'HTTP_ORIGIN' => 'https://attacker.example',
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testTheSamplesPageStartsRunsWithForms(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/durable/samples');

        self::assertGreaterThan(0, $crawler->filterXPath('//form[@method="post"][@action="/durable/samples/run/simple_activity"]')->count());
    }

    public function testDashboardReturns200WithWorkflowHeading(): void
    {
        $client = static::createClient();
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
        $client = static::createClient();
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
        $client = static::createClient();
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
}
