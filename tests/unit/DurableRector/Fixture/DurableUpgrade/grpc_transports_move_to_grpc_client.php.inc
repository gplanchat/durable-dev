<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Bridge\Temporal\Grpc\GrpcTransport;
use Gplanchat\Bridge\Temporal\Http\CurlGrpcTransport;
use Gplanchat\Bridge\Temporal\Http\GrpcWire;
use Gplanchat\Bridge\Temporal\Http\GuzzleGrpcTransport;

final class Transports
{
    public function __construct(private GrpcTransport $transport) {}

    public function types(): array
    {
        return [CurlGrpcTransport::class, GuzzleGrpcTransport::class, GrpcWire::UNKNOWN];
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Bridge\Temporal\Grpc\GrpcTransport;
use Gplanchat\Bridge\Temporal\Http\CurlGrpcTransport;
use Gplanchat\Bridge\Temporal\Http\GrpcWire;
use Gplanchat\Bridge\Temporal\Http\GuzzleGrpcTransport;

final class Transports
{
    public function __construct(private \Gplanchat\GrpcClient\GrpcTransport $transport) {}

    public function types(): array
    {
        return [\Gplanchat\GrpcClient\CurlGrpcTransport::class, \Gplanchat\GrpcClient\GuzzleGrpcTransport::class, \Gplanchat\GrpcClient\GrpcWire::UNKNOWN];
    }
}

?>
