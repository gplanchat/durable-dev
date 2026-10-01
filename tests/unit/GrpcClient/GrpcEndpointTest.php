<?php

declare(strict_types=1);

namespace unit\Gplanchat\GrpcClient;

use Gplanchat\GrpcClient\GrpcEndpoint;
use PHPUnit\Framework\TestCase;

final class GrpcEndpointTest extends TestCase
{
    public function testTheMetadataIsReadBackButNeverShownByADump(): void
    {
        $endpoint = new GrpcEndpoint('127.0.0.1:50051', metadata: ['authorization' => ['Bearer s3cret']]);

        self::assertSame(['authorization' => ['Bearer s3cret']], $endpoint->metadata());
        self::assertStringNotContainsString('s3cret', print_r($endpoint, true));
        self::assertSame([], (new GrpcEndpoint('127.0.0.1:50051'))->metadata());
    }
}
