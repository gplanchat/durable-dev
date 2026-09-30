<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use unit\DurableRector\Source\LegacyStringEvent;

// Its author retypes it by hand (UPGRADE): a wrap here would be a TypeError until then.
final class WritesALegacyEvent
{
    public function __invoke(string $executionId): LegacyStringEvent
    {
        return new LegacyStringEvent($executionId);
    }
}
