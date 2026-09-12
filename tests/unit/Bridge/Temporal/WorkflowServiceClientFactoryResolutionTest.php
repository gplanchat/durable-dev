<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use PHPUnit\Framework\TestCase;

/**
 * The resolution is a pure function of what was asked and what is installed, so every branch
 * is covered here whatever this machine has.
 */
final class WorkflowServiceClientFactoryResolutionTest extends TestCase
{
    public function testAutoTakesTheExtensionWhenItIsThereAndSaysNothing(): void
    {
        self::assertSame(['grpc', null], WorkflowServiceClientFactory::resolve('auto', true, true));
        self::assertSame(['grpc', null], WorkflowServiceClientFactory::resolve('auto', true, false));
    }

    public function testAutoFallsBackToCurlWithoutTheExtensionAndSaysWhy(): void
    {
        [$transport, $reason] = WorkflowServiceClientFactory::resolve('auto', false, true);

        self::assertSame('grpc-curl', $transport);
        self::assertIsString($reason);
        self::assertStringContainsString('ext-grpc is not loaded', $reason);
    }

    public function testAutoWithNothingInstalledNamesBothRemedies(): void
    {
        try {
            WorkflowServiceClientFactory::resolve('auto', false, false);
            self::fail('Nothing to resolve to.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('"grpc"', $e->getMessage());
            self::assertStringContainsString('durable-bridge-temporal-http', $e->getMessage());
        }
    }

    public function testAnExplicitTransportNeverFallsBack(): void
    {
        // The absent extension is the factory's problem to report, not the resolution's to hide.
        self::assertSame(['grpc', null], WorkflowServiceClientFactory::resolve('grpc', false, true));
        self::assertSame(['grpc-curl', null], WorkflowServiceClientFactory::resolve('grpc-curl', true, true));
        self::assertSame(['http', null], WorkflowServiceClientFactory::resolve('http', true, true));
    }

    public function testTheEffectiveTransportFollowsTheMachine(): void
    {
        $effective = WorkflowServiceClientFactory::effectiveTransport(TemporalConnection::fromDsn('temporal://127.0.0.1'));

        self::assertSame(\extension_loaded('grpc') ? 'grpc' : 'grpc-curl', $effective);
        self::assertSame('http', WorkflowServiceClientFactory::effectiveTransport(TemporalConnection::fromDsn('temporal+http://127.0.0.1')));
    }
}
