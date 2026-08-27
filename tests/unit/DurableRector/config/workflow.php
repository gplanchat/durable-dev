<?php

declare(strict_types=1);

use Gplanchat\Durable\Rector\Rector\WorkflowClassAttributesRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([WorkflowClassAttributesRector::class]);
