<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow;

class VersionedWorkflow
{
    private const OLD = Workflow::DEFAULT_VERSION;

    public function run(): \Generator
    {
        $version = yield Workflow::getVersion('add-discount', 1, 2);
        $named = yield Workflow::getVersion(changeId: 'audit', minSupported: 1, maxSupported: 3);

        if ((yield Workflow::getVersion('fee', Workflow::DEFAULT_VERSION, 1)) === Workflow::DEFAULT_VERSION) {
            return 0;
        }

        return $version + $named;
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Facade;

use Gplanchat\Durable\Versioning\ChangePoint;
use Temporal\Workflow;

class VersionedWorkflow
{
    public function __construct(private readonly \Gplanchat\Durable\WorkflowEnvironment $environment)
    {
    }
    private const OLD = ChangePoint::DEFAULT_VERSION;

    public function run()
    {
        $version = $this->environment->version('add-discount', 1, 2);
        $named = $this->environment->version(changeId: 'audit', minSupported: 1, maxSupported: 3);

        if (($this->environment->version('fee', ChangePoint::DEFAULT_VERSION, 1)) === ChangePoint::DEFAULT_VERSION) {
            return 0;
        }

        return $version + $named;
    }
}

?>
