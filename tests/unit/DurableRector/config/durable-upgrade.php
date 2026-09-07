<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// The set as a project loads it, not a list of rules copied out here: if the published set
// loses an entry, this test sees it.
return RectorConfig::configure()
    ->withSets([__DIR__ . '/../../../../src/DurableRector/config/sets/durable-upgrade.php']);
