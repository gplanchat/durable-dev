<?php

namespace unit\DurableRector\Fixture\AttributesAlpha8;

use Gplanchat\Durable\Attribute\Activity;
use Gplanchat\Durable\Attribute\ActivityMethod;
use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;

#[Activity('facturation')]
interface FacturationInterface
{
    #[ActivityMethod('encaisser')]
    public function encaisser(string $ordre): string;
}

#[AsDurableActivity(contract: FacturationInterface::class)]
final class Facturation implements FacturationInterface
{
    public function encaisser(string $ordre): string
    {
        return $ordre;
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\AttributesAlpha8;

use Gplanchat\Durable\Attribute\Activity;
use Gplanchat\Durable\Attribute\ActivityMethod;
use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;

#[\Gplanchat\Durable\Attribute\AsActivity('facturation')]
interface FacturationInterface
{
    #[\Gplanchat\Durable\Attribute\AsActivityMethod('encaisser')]
    public function encaisser(string $ordre): string;
}

#[\Gplanchat\Durable\Attribute\AsActivityHandler(contract: FacturationInterface::class)]
final class Facturation implements FacturationInterface
{
    public function encaisser(string $ordre): string
    {
        return $ordre;
    }
}

?>
