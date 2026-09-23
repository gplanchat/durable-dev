<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Grpc;

use Gplanchat\Bridge\Temporal\Grpc\ExtGrpcTransport;
use PHPUnit\Framework\TestCase;

final class ExtGrpcTransportTest extends TestCase
{
    public function testTheDeadlineGoesBackToTheMicrosecondsTheExtensionReads(): void
    {
        self::assertSame([], ExtGrpcTransport::callOptions(null), 'no deadline, no timeout key');
        self::assertSame(['timeout' => 2_500_000], ExtGrpcTransport::callOptions(2_500));
    }
}
