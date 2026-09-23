<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\ExtGrpcTransport;
use Gplanchat\Bridge\Temporal\Grpc\GrpcTransport;
use Gplanchat\Bridge\Temporal\Grpc\GrpcWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\Http\CurlGrpcTransport;
use Gplanchat\Bridge\Temporal\Http\JsonGatewayWorkflowServiceClient;
use Grpc\ChannelCredentials;
use Psr\Log\LoggerInterface;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

final class WorkflowServiceClientFactory
{
    /** @var array<string, true> the fallbacks already logged by this process, one line each */
    private static array $logged = [];

    public static function assertGrpcExtension(): void
    {
        if (!\extension_loaded('grpc')) {
            throw new \RuntimeException('PHP extension "grpc" is required for transport=grpc; without it, use transport=grpc-curl, which only needs the curl extension.');
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
        $transport = self::resolveLogged($settings, $logger);

        // The JSON gateway is not gRPC: a client of its own, not a transport.
        if (TemporalConnection::TRANSPORT_HTTP === $transport) {
            self::assertCurl($transport);

            return new JsonGatewayWorkflowServiceClient($settings);
        }

        return new GrpcWorkflowServiceClient(self::transportFor($transport, $settings));
    }

    /** The gRPC transport the connection resolves to, for a caller that speaks another gRPC service. */
    public static function createTransport(TemporalConnection $settings, ?LoggerInterface $logger = null): GrpcTransport
    {
        $transport = self::resolveLogged($settings, $logger);
        if (TemporalConnection::TRANSPORT_HTTP === $transport) {
            throw new \RuntimeException('transport=http is the JSON gateway, which carries no gRPC: there is no gRPC transport to build.');
        }

        return self::transportFor($transport, $settings);
    }

    private static function resolveLogged(TemporalConnection $settings, ?LoggerInterface $logger): string
    {
        [$transport, $reason] = self::resolve($settings->transport, \extension_loaded('grpc'), \extension_loaded('curl'));
        if (null !== $reason && !isset(self::$logged[$reason])) {
            self::$logged[$reason] = true;
            $message = 'Temporal: ' . $reason;
            // ponytail: a bare worker has no logger; error_log is where its operator looks.
            null === $logger ? error_log($message) : $logger->info($message, ['target' => $settings->target, 'transport' => $transport]);
        }

        return $transport;
    }

    private static function transportFor(string $transport, TemporalConnection $settings): GrpcTransport
    {
        if (TemporalConnection::TRANSPORT_GRPC === $transport) {
            return new ExtGrpcTransport($settings);
        }
        self::assertCurl($transport);

        return new CurlGrpcTransport($settings);
    }

    private static function assertCurl(string $transport): void
    {
        if (!\extension_loaded('curl')) {
            throw new \RuntimeException(\sprintf('transport=%s requires the PHP extension "curl".', $transport));
        }
    }

    /** The transport {@see create} would build for this connection on this machine. */
    public static function effectiveTransport(TemporalConnection $settings): string
    {
        return self::resolve($settings->transport, \extension_loaded('grpc'), \extension_loaded('curl'))[0];
    }

    /**
     * Which transport a request resolves to, and why when that is not what was asked.
     *
     * Pure, so every branch has a test regardless of what this machine has installed.
     *
     * @return array{0: string, 1: string|null} [effective transport, reason for a fallback]
     */
    public static function resolve(string $requested, bool $grpcLoaded, bool $curlLoaded): array
    {
        if (TemporalConnection::TRANSPORT_AUTO !== $requested) {
            return [$requested, null];
        }
        if ($grpcLoaded) {
            return [TemporalConnection::TRANSPORT_GRPC, null];
        }
        if ($curlLoaded) {
            return [TemporalConnection::TRANSPORT_GRPC_CURL, 'ext-grpc is not loaded, using curl over HTTP/2 (transport=auto).'];
        }

        throw new \RuntimeException('Temporal needs the PHP extension "grpc", or the PHP extension "curl" (gRPC over HTTP/2); neither is loaded.');
    }

    public static function createStub(TemporalConnection $settings): WorkflowServiceClient
    {
        self::assertGrpcExtension();

        return new WorkflowServiceClient($settings->target, self::channelOptions($settings));
    }

    /**
     * The ext-grpc channel options for this connection, shared by {@see createStub} and
     * {@see ExtGrpcTransport}.
     *
     * @return array{credentials: mixed}
     */
    public static function channelOptions(TemporalConnection $settings): array
    {
        $credentials = $settings->tls
            ? ChannelCredentials::createSsl()
            : ChannelCredentials::createInsecure();

        return ['credentials' => $credentials];
    }
}
