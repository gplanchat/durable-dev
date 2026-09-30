<?php

declare(strict_types=1);

use Gplanchat\Durable\Rector\Rector\ExecutionIdEventArgumentRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([ExecutionIdEventArgumentRector::class]);
