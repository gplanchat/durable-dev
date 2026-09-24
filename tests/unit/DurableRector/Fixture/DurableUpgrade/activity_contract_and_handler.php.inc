<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Attribute\Activity;
use Gplanchat\Durable\Attribute\ActivityMethod;
use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;

#[Activity('billing')]
interface BillingInterface
{
    #[ActivityMethod('collect')]
    public function collect(string $order): string;
}

#[AsDurableActivity(contract: BillingInterface::class)]
final class Billing implements BillingInterface
{
    public function collect(string $order): string
    {
        return $order;
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Attribute\Activity;
use Gplanchat\Durable\Attribute\ActivityMethod;
use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;

#[\Gplanchat\Durable\Attribute\AsActivity('billing')]
interface BillingInterface
{
    #[\Gplanchat\Durable\Attribute\AsActivityMethod('collect')]
    public function collect(string $order): string;
}

#[\Gplanchat\Durable\Attribute\AsActivityHandler(contract: BillingInterface::class)]
final class Billing implements BillingInterface
{
    public function collect(string $order): string
    {
        return $order;
    }
}

?>
