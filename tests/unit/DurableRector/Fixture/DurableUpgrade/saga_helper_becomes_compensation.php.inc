<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Workflow\Saga;
use Gplanchat\Durable\Workflow\Saga as Rollback;

final class Checkout
{
    /** @var Saga */
    private Saga $saga;

    public function __construct()
    {
        $this->saga = new Saga();
    }

    public function run(Saga $saga): Saga
    {
        $saga->addCompensation(static fn () => null);

        return new \Gplanchat\Durable\Workflow\Saga();
    }
}

final class Refund
{
    public function run(Rollback $rollback): Rollback
    {
        return new Rollback();
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Workflow\Saga;
use Gplanchat\Durable\Workflow\Saga as Rollback;

final class Checkout
{
    /** @var \Gplanchat\Durable\Workflow\Compensation */
    private \Gplanchat\Durable\Workflow\Compensation $saga;

    public function __construct()
    {
        $this->saga = new \Gplanchat\Durable\Workflow\Compensation();
    }

    public function run(\Gplanchat\Durable\Workflow\Compensation $saga): \Gplanchat\Durable\Workflow\Compensation
    {
        $saga->addCompensation(static fn () => null);

        return new \Gplanchat\Durable\Workflow\Compensation();
    }
}

final class Refund
{
    public function run(\Gplanchat\Durable\Workflow\Compensation $rollback): \Gplanchat\Durable\Workflow\Compensation
    {
        return new \Gplanchat\Durable\Workflow\Compensation();
    }
}

?>
