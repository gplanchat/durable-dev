<?php

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

/*
 * A module of the BENCH, not of the published package. It exists only to carry
 * the §1.3 probe topic: Magento can only resolve a queue handler from a
 * registered module, and `gplanchat/durable-magento` has no business shipping a
 * topic that does nothing but sleep. 4.1 will write the real roles over there;
 * this one will stay here, or disappear.
 */
ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Gplanchat_DurableProbe', __DIR__);
