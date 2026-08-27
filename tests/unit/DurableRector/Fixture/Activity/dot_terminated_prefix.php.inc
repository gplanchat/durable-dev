<?php

namespace unit\DurableRector\Fixture\Activity;

#[\Temporal\Activity\ActivityInterface(prefix: 'Order.')]
interface OrderActivities
{
    #[\Temporal\Activity\ActivityMethod(name: 'chargeCard')]
    public function charge(string $orderId): string;

    public function sendReceipt(string $charge): string;
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Activity;

#[\Gplanchat\Durable\Attribute\Activity(name: 'Order')]
interface OrderActivities
{
    #[\Gplanchat\Durable\Attribute\ActivityMethod(name: 'chargeCard')]
    public function charge(string $orderId): string;

    #[\Gplanchat\Durable\Attribute\ActivityMethod(name: 'sendReceipt')]
    public function sendReceipt(string $charge): string;
}

?>
