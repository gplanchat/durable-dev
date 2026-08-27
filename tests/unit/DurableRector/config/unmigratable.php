<?php

declare(strict_types=1);

use Gplanchat\Durable\Rector\Rector\UnmigratableTemporalCallRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([UnmigratableTemporalCallRector::class]);
