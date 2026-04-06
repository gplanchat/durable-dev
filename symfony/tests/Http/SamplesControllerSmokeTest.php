<?php

declare(strict_types=1);

namespace App\Tests\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests HTTP : vérifie que le container se construit correctement (routing, DI, templates)
 * et que les pages principales retournent HTTP 200 avec le contenu attendu.
 *
 * Ces tests auraient détecté la régression du DurableTemporalTransportFactoryPass
 * (mauvais type injecté → exception à l'initialisation du container).
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
        $this->assertStringContainsString('Synchrone', $content);
        $this->assertStringContainsString('Async', $content);
    }

    public function testSamplesIndexListsAllRegisteredScenarios(): void
    {
        $client = static::createClient();
        $client->request('GET', '/durable/samples');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        // Quelques scénarios représentatifs du catalogue
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
        $this->assertStringContainsString('Lire la documentation', $content);
        $this->assertStringContainsString('SimpleActivity', $content);
    }

    public function testDocumentationPageReturns200(): void
    {
        $client = static::createClient();
        $client->request('GET', '/documentation');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Documentation Durable', $content);
    }

    public function testSamplesRunSyncReturns200ForSimpleActivity(): void
    {
        $client = static::createClient();
        $client->request('GET', '/durable/samples/run/simple_activity');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Hello, World!', $content);
        $this->assertStringContainsString('Synchrone', $content);
    }

    public function testSamplesRunAsyncReturns200ForSimpleActivity(): void
    {
        $client = static::createClient();
        $client->request('GET', '/durable/samples/run/simple_activity?wait=0');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Async', $content);
    }

    public function testSamplesRunUnknownIdReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/durable/samples/run/nonexistent-scenario');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDashboardReturns200WithWorkflowHeading(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Workflow Dashboard', $content);
        $this->assertStringContainsString('Running', $content);
        $this->assertStringContainsString('Navigation des executions', $content);
        $this->assertStringContainsString('Affichage de', $content);
        $this->assertStringContainsString('Frise temporelle', $content);
        $this->assertStringContainsString('Historique des evenements', $content);
    }

    public function testDashboardShowsTimelineControlsAndLegend(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $normalized = strtoupper($content);
        $this->assertStringContainsString('ZOOM', $normalized);
        $this->assertStringContainsString('LIGNES', $normalized);
        $this->assertStringContainsString('EXECUTION', $normalized);
        $this->assertStringContainsString('ACTIVITY', $normalized);
        $this->assertStringContainsString('SIGNAL', $normalized);
        $this->assertStringContainsString('QUERY', $normalized);
        $this->assertStringContainsString('UPDATE', $normalized);
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
