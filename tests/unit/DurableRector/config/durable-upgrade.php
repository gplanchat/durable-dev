<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// Le set tel qu'un projet le charge, pas une liste de règles recopiée ici : si le set publié
// perd une entrée, ce test le voit.
return RectorConfig::configure()
    ->withSets([__DIR__ . '/../../../../src/DurableRector/config/sets/durable-upgrade.php']);
