<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A PSR-18 client and the PSR-17 factories it needs, for the JSON gateway: Guzzle's `Client` with
 * `Psr7\HttpFactory`, Symfony's `Psr18Client` (its own factory), or any other implementation.
 */
final readonly class Psr18Http
{
    public function __construct(
        public ClientInterface $client,
        public RequestFactoryInterface $requests,
        public StreamFactoryInterface $streams,
    ) {}
}
