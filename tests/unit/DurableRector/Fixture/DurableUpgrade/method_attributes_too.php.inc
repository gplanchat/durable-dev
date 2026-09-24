<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Attribute\QueryMethod;
use Gplanchat\Durable\Attribute\SignalMethod;
use Gplanchat\Durable\Attribute\UpdateMethod;

final class Cart
{
    #[QueryMethod('state')]
    public function state(): string
    {
        return 'ok';
    }

    #[SignalMethod('wakeUp')]
    public function wakeUp(): void
    {
    }

    #[UpdateMethod('add')]
    public function add(int $n): int
    {
        return $n;
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Attribute\QueryMethod;
use Gplanchat\Durable\Attribute\SignalMethod;
use Gplanchat\Durable\Attribute\UpdateMethod;

final class Cart
{
    #[\Gplanchat\Durable\Attribute\AsQueryMethod('state')]
    public function state(): string
    {
        return 'ok';
    }

    #[\Gplanchat\Durable\Attribute\AsSignalMethod('wakeUp')]
    public function wakeUp(): void
    {
    }

    #[\Gplanchat\Durable\Attribute\AsUpdateMethod('add')]
    public function add(int $n): int
    {
        return $n;
    }
}

?>
