<?php

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

/*
 * A module of the BENCH, not of the published package. It carries the §1.3
 * probe topic — Magento can only resolve a queue handler from a registered
 * module, and `gplanchat/durable-magento` has no business shipping a topic that
 * does nothing but sleep — plus the demonstration workflows, their activity
 * handlers, the order observer and the two `durable:demo*` commands. The
 * published module rides no Magento queue at all: its workers are commands.
 */
ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Gplanchat_DurableProbe', __DIR__);
