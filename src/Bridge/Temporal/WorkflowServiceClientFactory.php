<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\GrpcWorkflowServiceClient;
use Grpc\ChannelCredentials;
use Psr\Log\LoggerInterface;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

final class WorkflowServiceClientFactory
{
    private const CURL_CLIENT = 'Gplanchat\Bridge\TemporalHttp\CurlGrpcWorkflowServiceClient';

    private const HTTP_CLIENT = 'Gplanchat\Bridge\TemporalHttp\JsonGatewayWorkflowServiceClient';

    /** @var array<string, true> the fallbacks already logged by this process, one line each */
    private static array $logged = [];

    public static function assertGrpcExtension(): void
    {
        if (!\extension_loaded('grpc')) {
            throw new \RuntimeException('PHP extension "grpc" is required for transport=grpc; without it, install gplanchat/durable-bridge-temporal-http and use transport=grpc-curl.');
        }
    }

    /**
     * The client the connection asks for. With transport=auto (the default) the choice is made
     * here, once: ext-grpc when it is loaded, curl otherwise, and that fallback is logged once
     * per process. An explicit transport never falls back: asking for what is not installed
     * fails, loudly, at wiring time.
     */
    public static function create(TemporalConnection $settings, ?LoggerInterface $logger = null): WorkflowServiceClientInterface
    {
        [$transport, $reason] = self::resolve($settings->transport, \extension_loaded('grpc'), class_exists(self::CURL_CLIENT));
        if (null !== $reason && !isset(self::$logged[$reason])) {
            self::$logged[$reason] = true;
            $message = 'Temporal: ' . $reason;
            // ponytail: a bare worker has no logger; error_log is where its operator looks.
            null === $logger ? error_log($message) : $logger->info($message, ['target' => $settings->target, 'transport' => $transport]);
        }

        if (TemporalConnection::TRANSPORT_GRPC === $transport) {
            return new GrpcWorkflowServiceClient(self::createStub($settings));
        }

        $class = TemporalConnection::TRANSPORT_HTTP === $transport ? self::HTTP_CLIENT : self::CURL_CLIENT;
        if (!class_exists($class)) {
            throw new \RuntimeException(\sprintf('transport=%s requires the gplanchat/durable-bridge-temporal-http package.', $transport));
        }

        /** @var class-string<WorkflowServiceClientInterface> $class */
        return new $class($settings);
    }

    /** The transport {@see create} would build for this connection on this machine. */
    public static function effectiveTransport(TemporalConnection $settings): string
    {
        return self::resolve($settings->transport, \extension_loaded('grpc'), class_exists(self::CURL_CLIENT))[0];
    }

    /**
     * Which transport a request resolves to, and why when that is not what was asked.
     *
     * Pure, so every branch has a test regardless of what this machine has installed.
     *
     * @return array{0: string, 1: string|null} [effective transport, reason for a fallback]
     */
    public static function resolve(string $requested, bool $grpcLoaded, bool $httpPackageInstalled): array
    {
        if (TemporalConnection::TRANSPORT_AUTO !== $requested) {
            return [$requested, null];
        }
        if ($grpcLoaded) {
            return [TemporalConnection::TRANSPORT_GRPC, null];
        }
        if ($httpPackageInstalled) {
            return [TemporalConnection::TRANSPORT_GRPC_CURL, 'ext-grpc is not loaded, using curl over HTTP/2 (transport=auto).'];
        }

        throw new \RuntimeException('Temporal needs either the PHP extension "grpc" or the gplanchat/durable-bridge-temporal-http package (curl over HTTP/2); neither is installed.');
    }

    public static function createStub(TemporalConnection $settings): WorkflowServiceClient
    {
        self::assertGrpcExtension();

        $credentials = $settings->tls
            ? ChannelCredentials::createSsl()
            : ChannelCredentials::createInsecure();

        return new WorkflowServiceClient($settings->target, ['credentials' => $credentials]);
    }
}
