<?php

declare(strict_types=1);

use Gplanchat\Durable\Rector\Rector\WorkflowFiberDriverRunRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([WorkflowFiberDriverRunRector::class]);
