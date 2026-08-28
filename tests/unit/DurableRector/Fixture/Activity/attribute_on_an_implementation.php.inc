<?php

namespace unit\DurableRector\Fixture\Activity;

// #[ActivityInterface] is TARGET_CLASS, so it lands on classes too. Durable resolves names from
// whatever contract a stub is given, so a class that declares itself one is rewritten like any other.
#[\Temporal\Activity\ActivityInterface(prefix: 'Order.')]
final class OrderActivitiesImplementation
{
    public function charge(string $orderId): string
    {
        return $orderId;
    }

    private function helper(): void
    {
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Activity;

// #[ActivityInterface] is TARGET_CLASS, so it lands on classes too. Durable resolves names from
// whatever contract a stub is given, so a class that declares itself one is rewritten like any other.
#[\Gplanchat\Durable\Attribute\AsActivity(name: 'Order')]
final class OrderActivitiesImplementation
{
    #[\Gplanchat\Durable\Attribute\AsActivityMethod(name: 'charge')]
    public function charge(string $orderId): string
    {
        return $orderId;
    }

    private function helper(): void
    {
    }
}

?>
