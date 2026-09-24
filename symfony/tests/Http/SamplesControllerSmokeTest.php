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
        $client->request('GET', '/durable/samples/run/simple_activity');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Hello, World!', $content);
        $this->assertStringContainsString('Synchronous', $content);
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
}
