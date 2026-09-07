<?php

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

/*
 * The Composer package is called `gplanchat/durable-magento`, the Magento module
 * `Gplanchat_DurableModule`. The two conventions never meet: Packagist wants the family
 * first, `bin/magento module:status` wants the Magento vendor.
 */
ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Gplanchat_DurableModule', __DIR__);
