<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

final class BuildsAnInvoker
{
    public function make(object $handler, string $contract, string $method): object
    {
        return new \Gplanchat\Durable\Bundle\Activity\PayloadToContractMethodInvoker($handler, $contract, $method);
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

final class BuildsAnInvoker
{
    public function make(object $handler, string $contract, string $method): object
    {
        return new \Gplanchat\Durable\Activity\PayloadToContractMethodInvoker($handler, $contract, $method);
    }
}

?>
