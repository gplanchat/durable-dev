<?php

declare(strict_types=1);

namespace Gplanchat\GrpcClient;

/** Where a transport sends its calls: host:port, TLS files, and the metadata every call carries. */
final readonly class GrpcEndpoint
{
    /** Wrapped so a dump of the endpoint (profiler, print_r, a stack trace) never shows it. */
    private ?\SensitiveParameterValue $metadata;

    /**
     * @param string                      $target   host:port
     * @param string|null                 $tlsCa    PEM file of the CA that signs the server certificate; null trusts the system store
     * @param string|null                 $tlsCert  PEM file of the client certificate, for mTLS (with $tlsKey)
     * @param string|null                 $tlsKey   PEM file of the client key
     * @param array<string, list<string>> $metadata sent with every call, e.g. an authorization header
     */
    public function __construct(
        public string $target,
        public bool $tls = false,
        public ?string $tlsCa = null,
        public ?string $tlsCert = null,
        public ?string $tlsKey = null,
        #[\SensitiveParameter]
        array $metadata = [],
        public string $userAgent = 'gplanchat-grpc-client/php',
    ) {
        $this->metadata = [] === $metadata ? null : new \SensitiveParameterValue($metadata);
    }

    /** @return array<string, list<string>> */
    public function metadata(): array
    {
        /** @var array<string, list<string>> */
        return $this->metadata?->getValue() ?? [];
    }
}
