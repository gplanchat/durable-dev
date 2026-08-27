<?php

declare(strict_types=1);

use Gplanchat\Durable\Rector\Rector\ActivityContractAttributesRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([ActivityContractAttributesRector::class]);
