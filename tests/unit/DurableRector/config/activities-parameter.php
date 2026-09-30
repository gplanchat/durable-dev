<?php

declare(strict_types=1);

use Gplanchat\Durable\Rector\Rector\ActivitiesParameterRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([ActivitiesParameterRector::class]);
