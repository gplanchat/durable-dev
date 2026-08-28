<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()->withSets([
    __DIR__ . '/../../../../src/DurableRector/config/sets/durable-attributes-alpha8.php',
]);
