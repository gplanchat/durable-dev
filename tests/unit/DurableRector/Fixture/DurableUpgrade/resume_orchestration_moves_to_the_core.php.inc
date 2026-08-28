<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

final class WiresTheResume
{
    public function handler(): string
    {
        return \Gplanchat\Durable\Bundle\Handler\ResumeWorkflowHandler::class;
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

final class WiresTheResume
{
    public function handler(): string
    {
        return \Gplanchat\Durable\Handler\ResumeWorkflowHandler::class;
    }
}

?>
