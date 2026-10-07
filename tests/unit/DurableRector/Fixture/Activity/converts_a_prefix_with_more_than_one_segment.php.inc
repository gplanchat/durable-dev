<?php

namespace unit\DurableRector\Fixture\Activity;

// 'Billing.Order.' gives 'Billing.Order.charge' on both engines.
#[\Temporal\Activity\ActivityInterface(prefix: 'Billing.Order.')]
interface BillingOrderActivities
{
    public function charge(string $orderId): string;
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Activity;

// 'Billing.Order.' gives 'Billing.Order.charge' on both engines.
#[\Gplanchat\Durable\Attribute\AsActivity(name: 'Billing.Order')]
interface BillingOrderActivities
{
    #[\Gplanchat\Durable\Attribute\AsActivityMethod(name: 'charge')]
    public function charge(string $orderId): string;
}

?>
