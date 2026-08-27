<?php

declare(strict_types=1);

use Gplanchat\Durable\Rector\Rector\TemporalFacadeToEnvironmentRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([TemporalFacadeToEnvironmentRector::class]);
