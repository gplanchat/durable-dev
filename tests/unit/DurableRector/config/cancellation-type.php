<?php

declare(strict_types=1);

use Gplanchat\Durable\Rector\Rector\ActivityCancellationTypeOptionRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([ActivityCancellationTypeOptionRector::class]);
